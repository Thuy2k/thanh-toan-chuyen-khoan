<?php

/**
 * Plugin Name: BizGPT - Thanh Toán Quét Mã QR Code Tự Động
 * Plugin URI: https://bizgpt.vn
 * Description: Sinh mã QR động (VietQR) và tự động xác nhận thanh toán chuyển khoản. Chạy độc lập, KHÔNG cần WooCommerce.
 * Author: BizGPT Team
 * Author URI: https://bizgpt.vn
 * Text Domain: thanh-toan-chuyen-khoan
 * Domain Path: /languages
 * Version: 2.0.0
 * License: GNU General Public License v3.0
 */

if (!defined('ABSPATH')) {
	exit;
}

define('TTCK_DIR', plugin_dir_path(__FILE__));
define('TTCK_URL', plugins_url('/', __FILE__));
define('TTCK_TEST', 0);

require_once TTCK_DIR . 'inc/functions.php';
require_once TTCK_DIR . 'inc/class-ttck-banks.php';
require_once TTCK_DIR . 'inc/class-ttck-payments.php';
// Nạp TRƯỚC class-ttck-api.php: từ nay API lấy tài khoản nhận tiền từ file này
require_once TTCK_DIR . 'inc/class-ttck-account-file.php';
require_once TTCK_DIR . 'inc/class-ttck-api.php';

class TTCKPayment
{
	var $domain = 'thanh-toan-chuyen-khoan';
	var $settings = array();
	var $Admin_Page = null;

	static $default_settings = array(
		'bank_transfer' => array(
			'case_insensitive'      => 'yes',
			'enabled'               => 'yes',
			'title'                 => 'Chuyển khoản ngân hàng 24/7',
			'secure_token'          => '',
			'transaction_prefix'    => 'ABC',
			'extra_text'            => '',
			'acceptable_difference' => 1000,
			'viet_qr'               => 'yes',
		),
		// bank_id => array( array('account_name','account_number','bank_name'), ... )
		'bank_transfer_accounts'  => array(),
		// bank_id => array('enabled' => 'yes|no', 'title' => '', 'sort' => 0)
		'bank_meta'               => array(),
		'qr_engine'               => 'vietqr',   // vietqr | local
		'payment_expire_minutes'  => 30,
		'telegram_token'          => '',
		'telegram_chatid'         => '',
		'telegram_webhook_secret' => '',
		'tgs_hmac_secret'         => '',
		'webhook'                 => '',
		'auto_check_status'       => 0,
	);

	public function __construct()
	{
		add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
		add_action('init', array($this, 'init'));

		register_activation_hook(__FILE__, array($this, 'activate'));
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));

		$this->settings = self::get_settings();
	}

	function activate()
	{
		if (version_compare(phpversion(), '5.6', '<')) {
			wp_die('You need to update your PHP version. Require: PHP 5.6+');
		}
		if (!extension_loaded('gd')) {
			wp_die('Please activate PHP GD library.');
		}

		TTCK_Payments::install();
		update_option('ttck_db_version', TTCK_Payments::DB_VERSION, true);
		self::migrate_from_woocommerce();

		// Không redirect ở đây: activation hook chạy sau khi wp-admin đã xuất HTML.
		set_transient('ttck_activation_redirect', 1, 60);
	}

	function deactivate()
	{
		wp_clear_scheduled_hook('ttck_daily_maintenance');
	}

	public function init()
	{
		// Phải chạy TRƯỚC khi TTCK_Admin_Page::save_settings() đọc $_POST.
		$this->restore_masked_secrets();

		TTCK_Payments::maybe_install();
		self::migrate_from_woocommerce();

		add_action('admin_init', array($this, 'maybe_activation_redirect'));
		add_action('rest_api_init', array($this, 'register_rest_routes'));

		$this->settings = self::get_settings();

		if (is_admin()) {
			require_once TTCK_DIR . 'inc/class-ttck-admin-page.php';
			$this->Admin_Page = new TTCK_Admin_Page();

			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		}

		// Webhook từ app ngân hàng trên điện thoại.
		add_action('wp_ajax_nopriv_paid_order_ttck', array($this, 'pc_payment_handler'));
		add_action('wp_ajax_paid_order_ttck', array($this, 'pc_payment_handler'));

		// Poll trạng thái thanh toán.
		add_action('wp_ajax_nopriv_fetch_order_status_ttck', array($this, 'fetch_order_status'));
		add_action('wp_ajax_fetch_order_status_ttck', array($this, 'fetch_order_status'));

		add_action('wp_ajax_nopriv_auth_sync_status_ttck', array($this, 'auth_sync_status_ttck'));
		add_action('wp_ajax_auth_sync_status_ttck', array($this, 'auth_sync_status_ttck'));

		// Dọn các yêu cầu thanh toán quá hạn.
		add_action('ttck_daily_maintenance', array('TTCK_Payments', 'expire_stale'));
		if (!wp_next_scheduled('ttck_daily_maintenance')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ttck_daily_maintenance');
		}
	}

	/**
	 * CSS/JS chỉ nạp trên các trang cài đặt của plugin.
	 */
	public function enqueue_admin_assets()
	{
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		if (strpos($page, 'ttck') !== 0) {
			return;
		}

		wp_enqueue_style('ttck-style', TTCK_URL . 'assets/css/style.css', array(), '2.0.0');
		wp_enqueue_script('ttck-qrcode', TTCK_URL . 'assets/js/easy.qrcode.js', array('jquery'), '2.0.0', true);
		wp_enqueue_script('ttck-js', TTCK_URL . 'assets/js/js.js', array('jquery'), '2.0.0', true);
	}

	/* ---------------------------------------------------------------------
	 * Cấu hình
	 * ------------------------------------------------------------------ */

	static function get_settings()
	{
		$settings = get_option('ttck', self::$default_settings);
		if (!is_array($settings)) {
			$settings = array();
		}

		// wp_parse_args() chỉ merge cấp 1 nên các key con thiếu vẫn undefined.
		return self::merge_defaults($settings, self::$default_settings);
	}

	static function merge_defaults(array $settings, array $defaults)
	{
		foreach ($defaults as $key => $default_value) {
			if (!array_key_exists($key, $settings)) {
				$settings[$key] = $default_value;
			} elseif (is_array($default_value) && is_array($settings[$key]) && $key !== 'bank_transfer_accounts') {
				$settings[$key] = self::merge_defaults($settings[$key], $default_value);
			}
		}

		return $settings;
	}

	/**
	 * Đọc một cấu hình. $key có thể là chuỗi hoặc mảng đường dẫn lồng nhau.
	 *
	 *     TTCKPayment::get_setting('qr_engine', 'vietqr');
	 *     TTCKPayment::get_setting(['bank_transfer', 'transaction_prefix'], '');
	 */
	static function get_setting($key, $default = null)
	{
		$value = self::get_settings();

		foreach ((array) $key as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return $default;
			}
			$value = $value[$segment];
		}

		return $value;
	}

	static function update_settings(array $data)
	{
		if (!empty($data)) {
			update_option('ttck', $data);
		}
	}

	/**
	 * Trạng thái bật/tắt và tiêu đề từng ngân hàng trước đây nằm trong option
	 * `woocommerce_ttck_up_<bank>_settings` của WooCommerce. Chuyển một lần sang
	 * `ttck[bank_meta]` để bỏ WooCommerce mà không mất cấu hình của 650 site.
	 */
	static function migrate_from_woocommerce()
	{
		if (get_option('ttck_wc_migrated')) {
			return;
		}

		$settings = self::get_settings();
		$accounts = is_array($settings['bank_transfer_accounts']) ? $settings['bank_transfer_accounts'] : array();
		$meta     = is_array($settings['bank_meta']) ? $settings['bank_meta'] : array();
		$sort     = 0;

		foreach (array_keys($accounts) as $bank_id) {
			$bank_id = strtolower((string) $bank_id);
			if (isset($meta[$bank_id])) {
				continue;
			}

			$wc_settings = get_option('woocommerce_ttck_up_' . $bank_id . '_settings', array());
			$wc_settings = is_array($wc_settings) ? $wc_settings : array();

			$meta[$bank_id] = array(
				'enabled' => (isset($wc_settings['enabled']) && 'yes' === $wc_settings['enabled']) ? 'yes' : 'no',
				'title'   => isset($wc_settings['title']) ? (string) $wc_settings['title'] : '',
				'sort'    => $sort++,
			);
		}

		$settings['bank_meta'] = $meta;
		self::update_settings($settings);
		update_option('ttck_wc_migrated', 1, true);
	}

	/* ---------------------------------------------------------------------
	 * Danh mục ngân hàng (giữ tên hàm cũ cho code đang gọi sẵn)
	 * ------------------------------------------------------------------ */

	static function get_list_banks()
	{
		$banks = array();
		foreach (TTCK_Banks::all() as $id => $bank) {
			$banks[$id] = $bank['label'];
		}

		return $banks;
	}

	/**
	 * @return array bin => bank_id
	 */
	static function get_list_bin()
	{
		$bins = array();
		foreach (TTCK_Banks::all() as $id => $bank) {
			if ($bank['bin'] !== '') {
				$bins[$bank['bin']] = $id;
			}
		}

		return $bins;
	}

	static function get_bank_icon($name, $img = false)
	{
		$url = TTCK_Banks::icon_url($name);
		if ($url === '') {
			return $img ? '' : '';
		}

		return $img
			? '<img class="ttck-bank-icon" title="' . esc_attr(strtoupper($name)) . '" src="' . esc_url($url) . '"/>'
			: $url;
	}

	/**
	 * Gắn thêm chuỗi ngẫu nhiên trước mã giao dịch (nếu admin có cấu hình).
	 */
	static function transaction_text($code, $settings = null)
	{
		if ($settings === null) {
			$settings = self::get_settings();
		}

		$texts = !empty($settings['bank_transfer']['extra_text']) ? $settings['bank_transfer']['extra_text'] : '';
		if ($texts) {
			$texts = array_filter(explode("\n", $texts));
			if (count($texts)) {
				return $texts[array_rand($texts)] . ' ' . $code;
			}
		}

		return $code;
	}

	public function add_settings_link($links)
	{
		$settings = array('<a href="' . admin_url('admin.php?page=ttck') . '">' . __('Settings', 'thanh-toan-chuyen-khoan') . '</a>');

		return array_reverse(array_merge($links, $settings));
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	public function register_rest_routes()
	{
		register_rest_route('ttck/v1', '/telegram-webhook', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'telegram_webhook_handler'),
			'permission_callback' => '__return_true',
		));

		register_rest_route('ttck/v1', '/qr', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'render_payment_qr'),
			'permission_callback' => '__return_true',
		));
	}

	/**
	 * Render ảnh QR của một yêu cầu thanh toán ngay tại server.
	 */
	public function render_payment_qr($request)
	{
		$pid = (int) $request->get_param('pid');
		$key = (string) $request->get_param('k');

		$payment = TTCK_Payments::get($pid);
		if (!$payment || $key === '' || !hash_equals((string) $payment['qr_key'], $key)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Not found'), 404);
		}

		$bank   = TTCK_Banks::get($payment['bank_id']);
		$amount = (int) round((float) $payment['amount']);
		$text   = '';

		if ($payment['bin'] !== '' && is_numeric($payment['bin'])) {
			$text = TTCK_Banks::vietqr_payload($payment['bin'], $payment['account_number'], $amount, $payment['content']);
		} elseif ('momo' === $bank['qr']) {
			$text = sprintf('2|99|%s|||0|0|%d|%s|transfer_myqr', $payment['account_number'], $amount, $payment['content']);
		} elseif ('viettelpay' === $bank['qr']) {
			$text = wp_json_encode(array(
				'bankCode'        => 'VTT',
				'bankcodeList'    => array('VTT'),
				'cust_mobile'     => $payment['account_number'],
				'transAmountList' => array($amount),
				'trans_amount'    => $amount,
				'trans_content'   => $payment['content'],
				'transfer_type'   => 'MYQR',
			));
		}

		if ($text === '') {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Bank does not support QR'), 400);
		}

		require_once TTCK_DIR . 'lib/phpqrcode/qrlib.php';

		nocache_headers();
		header('Content-Type: image/png');
		QRcode::png($text, false, QR_ECLEVEL_M, 8, 2);
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Trạng thái thanh toán
	 * ------------------------------------------------------------------ */

	public function auth_sync_status_ttck()
	{
		wp_send_json(array(
			'oauth_status' => (bool) self::get_setting(array('bank_transfer', 'secure_token'), ''),
			'timestamp'    => time(),
		));
	}

	/**
	 * Trả về trạng thái của một yêu cầu thanh toán.
	 * Nhận `payment_id` (mới) hoặc `order_id` (tên tham số cũ).
	 */
	public function fetch_order_status()
	{
		$id = (int) ($_REQUEST['payment_id'] ?? $_REQUEST['order_id'] ?? 0);

		$status = $id > 0 ? TTCK_API::get_status($id) : null;
		if (!$status) {
			wp_send_json(array('status' => 'not_found', 'is_paid' => false));
		}

		wp_send_json($status);
	}

	/* ---------------------------------------------------------------------
	 * Webhook nhận biến động số dư (từ app điện thoại)
	 * ------------------------------------------------------------------ */

	public function pc_payment_handler()
	{
		$txtBody  = file_get_contents('php://input');
		$jsonBody = json_decode($txtBody);

		if (!$txtBody || !$jsonBody) {
			wp_send_json(array('error' => 'Missing body'));
		}

		if (isset($jsonBody->error) && $jsonBody->error != 0) {
			wp_send_json(array('error' => 'An error occurred'));
		}

		$header = ttck_getHeader();
		$token  = isset($header['Secure-Token']) ? $header['Secure-Token'] : '';

		// So sánh hằng thời gian, phân biệt hoa/thường.
		$expected_token = (string) self::get_setting(array('bank_transfer', 'secure_token'), '');
		if ($expected_token === '' || !hash_equals($expected_token, (string) $token)) {
			wp_send_json(array('error' => 'Missing secure_token or wrong secure_token'));
		}

		$settings         = self::get_settings();
		$prefix           = (string) $settings['bank_transfer']['transaction_prefix'];
		$case_insensitive = $settings['bank_transfer']['case_insensitive'];

		$result  = array('msg' => array(), 'error' => 1, 'rawInput' => $txtBody);
		$bankMsg = '';
		$domain  = parse_url(home_url(), PHP_URL_HOST);

		if (!empty($jsonBody->data)) {
			foreach ($jsonBody->data as $transaction) {
				$result['_ok'] = 1;

				$des = $transaction->description;
				if (ttck_is_JSON($des)) {
					$desJson = is_string($des) ? json_decode($des, true) : $des;
					if (is_array($desJson) && isset($desJson['code'])) {
						$des = $desJson['code'];
					}
				}

				$bankMsg = sprintf(
					"Thông báo giao dịch:\nTrang web: %s\nSố tiền: %s\nMã: %s\nTin nhắn: %s",
					$domain,
					number_format($transaction->amount),
					ttck_parse_code($des, $prefix, $case_insensitive),
					$transaction->description
				);

				$ref_code = ttck_parse_code($des, $prefix, $case_insensitive);
				$payment  = null;

				if (!is_null($ref_code)) {
					$payment = TTCK_Payments::get_by_ref($ref_code);
					if (!$payment) {
						// Dự phòng: mã cũ có thể lệch hoa/thường so với lúc tạo.
						$payment_id = ttck_parse_order_id($des, $prefix, $case_insensitive);
						$payment    = $payment_id ? TTCK_Payments::get($payment_id) : null;
					}
				}

				// Lưới an toàn: nội dung CK kiểu POS là mã phiếu bán (không có
				// <tiền tố>ID). Dò từng cụm chữ-số trong nội dung theo bill_code.
				if (!$payment) {
					if (preg_match_all('/[A-Za-z0-9.]{6,}/', (string) $des, $tokens)) {
						foreach ($tokens[0] as $token) {
							$token = strtoupper(trim($token, '.'));
							$payment = TTCK_Payments::get_by_bill_code($token);
							if (!$payment) {
								$payment = TTCK_Payments::get_by_bill_code(preg_replace('/Z+$/', '', $token));
							}
							if ($payment) {
								$ref_code = $payment['ref_code'];
								break;
							}
						}
					}
				}

				if (!$payment) {
					$result['msg'][] = 'Payment not found from transaction content: ' . $des;
					continue;
				}

				$ref_code = $ref_code ?: $payment['ref_code'];

				$settled = TTCK_Payments::settle($payment['id'], $transaction->amount, array(
					'description' => (string) $transaction->description,
					'account'     => isset($transaction->subAccId) ? (string) $transaction->subAccId : '',
				));

				if (is_wp_error($settled)) {
					$code = $settled->get_error_code();
					if ('ttck_already_paid' === $code) {
						$result['error']  = 0;
						$result['msg'][]  = 'Transaction processed before ' . $ref_code . ' success';
						break;
					}
					if ('ttck_expired' === $code) {
						$result['error'] = 1;
						$result['msg'][] = 'Payment request ' . $ref_code . ' has expired';
						continue;
					}
					$result['error'] = 1;
					$result['msg'][] = $settled->get_error_message();
					continue;
				}

				if (TTCK_Payments::STATUS_PAID === $settled['status']) {
					$result['error'] = 0;
					$result['msg'][] = 'Transaction processing ' . $ref_code . ' success';

					$tolerance = abs((float) $settings['bank_transfer']['acceptable_difference']);
					if ((float) $settled['paid_amount'] > (float) $settled['amount'] + $tolerance) {
						$result['msg'][] = __('Order has been overpaid', 'thanh-toan-chuyen-khoan');
					}
					break;
				}

				$result['error'] = 1;
				$result['msg'][] = __('The order is underpaid so it is not completed', 'thanh-toan-chuyen-khoan');
			}
		}

		$this->notify_telegram_group($bankMsg);
		$this->forward_to_external_webhook($txtBody, $result);

		$result['msg'] = join('. ', $result['msg']);

		wp_send_json($result);
	}

	private function notify_telegram_group($message)
	{
		$settings = self::get_settings();

		if (empty($settings['telegram_token']) || empty($settings['telegram_chatid']) || $message === '') {
			return;
		}

		$token = trim($settings['telegram_token']);
		if (substr($token, 0, 3) !== 'bot') {
			$token = 'bot' . $token;
		}

		wp_remote_get(
			'https://api.telegram.org/' . $token . '/sendMessage?chat_id=' . rawurlencode(trim($settings['telegram_chatid']))
				. '&text=' . rawurlencode(substr($message, 0, 4000)),
			array('timeout' => 20, 'httpversion' => '1.1')
		);
	}

	private function forward_to_external_webhook($txtBody, &$result)
	{
		$settings = self::get_settings();

		if (empty($settings['webhook']) || !filter_var($settings['webhook'], FILTER_VALIDATE_URL)) {
			return;
		}

		$resp = wp_remote_post($settings['webhook'], array(
			'method'      => 'POST',
			'timeout'     => 20,
			'redirection' => 5,
			'blocking'    => false,
			'headers'     => array('Content-Type' => 'application/json'),
			'body'        => $txtBody,
		));

		if (is_wp_error($resp)) {
			$result['msg'][] = $resp->get_error_message();
		}
	}

	/* ---------------------------------------------------------------------
	 * Telegram webhook (chuyển tiếp tin nhắn biến động số dư)
	 * ------------------------------------------------------------------ */

	public function telegram_webhook_handler($request)
	{
		$settings = self::get_settings();

		$secure_token   = trim((string) $settings['bank_transfer']['secure_token']);
		$telegram_token = trim((string) $settings['telegram_token']);
		$webhook_secret = trim((string) $settings['telegram_webhook_secret']);

		$payload = $request->get_json_params();
		$chat_id = $this->extract_telegram_chat_id($payload);

		if ($secure_token === '' && $webhook_secret === '') {
			$this->send_telegram_reply($telegram_token, $chat_id, "⚠️ Chưa cấu hình TTCK. Vào plugin → Lưu lại.");
			return new WP_REST_Response(array(
				'ok'      => false,
				'message' => 'Missing TTCK secure token/webhook secret. Please save TTCK settings first.',
			), 200);
		}

		$secret_header = trim((string) $request->get_header('x-telegram-bot-api-secret-token'));
		if ($secret_header === '' && isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])) {
			$secret_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']));
		}
		if ($secret_header === '' && function_exists('getallheaders')) {
			$all_headers = getallheaders();
			if (is_array($all_headers)) {
				foreach ($all_headers as $header_name => $header_value) {
					if (strtolower((string) $header_name) === 'x-telegram-bot-api-secret-token') {
						$secret_header = sanitize_text_field((string) $header_value);
						break;
					}
				}
			}
		}

		$secret_query = trim((string) $request->get_param('token'));
		if ($secret_query === '' && isset($_GET['token'])) {
			$secret_query = sanitize_text_field(wp_unslash($_GET['token']));
		}

		$provided_secret = $secret_header !== '' ? $secret_header : $secret_query;
		$expected_secret = $webhook_secret !== '' ? $webhook_secret : $secure_token;

		if (!($provided_secret !== '' && $expected_secret !== '' && hash_equals($expected_secret, $provided_secret))) {
			// Trả 200 để Telegram không retry liên tục.
			return new WP_REST_Response(array('ok' => false, 'message' => 'Invalid webhook secret.'), 200);
		}

		// Xác thực chữ ký HMAC (chỉ bắt buộc khi đã cấu hình tgs_hmac_secret).
		$hmac_secret = trim((string) $settings['tgs_hmac_secret']);
		if ($hmac_secret !== '') {
			$ts    = trim((string) ($request->get_header('x-tgs-timestamp') ?: ''));
			$nonce = trim((string) ($request->get_header('x-tgs-nonce') ?: ''));
			$sig   = trim((string) ($request->get_header('x-tgs-signature') ?: ''));

			if ($ts === '' || $nonce === '' || $sig === '') {
				return new WP_REST_Response(array('ok' => false, 'message' => 'Missing HMAC headers.'), 200);
			}
			if (abs(time() - intval($ts)) > 120) {
				return new WP_REST_Response(array('ok' => false, 'message' => 'Request timestamp expired.'), 200);
			}

			$nonce_key = 'tgs_nonce_' . preg_replace('/[^a-zA-Z0-9\-]/', '', substr($nonce, 0, 64));
			if (get_transient($nonce_key)) {
				return new WP_REST_Response(array('ok' => false, 'message' => 'Nonce already used.'), 200);
			}
			set_transient($nonce_key, 1, 300);

			$canonical = $ts . "\n" . $nonce . "\n" . hash('sha256', $request->get_body());
			if (!hash_equals(hash_hmac('sha256', $canonical, $hmac_secret), $sig)) {
				return new WP_REST_Response(array('ok' => false, 'message' => 'Invalid HMAC signature.'), 200);
			}
		}

		if (!is_array($payload)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Invalid Telegram payload.'), 200);
		}

		$text = $this->extract_telegram_text($payload);
		if ($text === '') {
			return new WP_REST_Response(array(
				'ok'       => true,
				'accepted' => false,
				'message'  => 'No message text/caption found.',
			), 200);
		}

		$amount = $this->extract_telegram_amount($text);
		if ($amount <= 0) {
			$this->send_telegram_reply($telegram_token, $chat_id, "❓ Không đọc được số tiền từ tin nhắn.");
			return new WP_REST_Response(array(
				'ok'       => true,
				'accepted' => false,
				'message'  => 'Cannot parse transfer amount from Telegram message.',
				'text'     => $text,
			), 200);
		}

		if ($secure_token === '') {
			$this->send_telegram_reply($telegram_token, $chat_id, "⚠️ Chưa có secure token. Vào cài đặt TTCK → Lưu lại.");
			return new WP_REST_Response(array(
				'ok'       => false,
				'accepted' => false,
				'message'  => 'Missing TTCK secure token. Please click Save on TTCK settings once.',
			), 200);
		}

		$response = wp_remote_post(add_query_arg('action', 'paid_order_ttck', admin_url('admin-ajax.php')), array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Secure-Token' => $secure_token,
			),
			'body'    => wp_json_encode(array(
				'data' => array(
					array(
						'amount'      => $amount,
						'description' => $text,
						'subAccId'    => 'telegram',
					),
				),
			)),
		));

		if (is_wp_error($response)) {
			$this->send_telegram_reply($telegram_token, $chat_id, "❌ Lỗi kết nối nội bộ: " . $response->get_error_message());
			return new WP_REST_Response(array(
				'ok'       => false,
				'accepted' => true,
				'message'  => $response->get_error_message(),
			), 200);
		}

		$http_code = intval(wp_remote_retrieve_response_code($response));
		$body_raw  = ltrim((string) wp_remote_retrieve_body($response), "\xEF\xBB\xBF");
		$decoded   = json_decode($body_raw, true);

		$this->send_telegram_reply(
			$telegram_token,
			$chat_id,
			$this->build_telegram_reply($decoded, $amount, $text, $settings, $http_code)
		);

		return new WP_REST_Response(array(
			'ok'             => true,
			'accepted'       => true,
			'forward_status' => $http_code,
			'forward_result' => is_array($decoded) ? $decoded : $body_raw,
		), 200);
	}

	private function build_telegram_reply($decoded, $amount, $text, $settings, $http_code)
	{
		$amount_fmt = number_format($amount, 0, '.', '.') . 'đ';
		$ref_code   = ttck_parse_code(
			$text,
			$settings['bank_transfer']['transaction_prefix'],
			$settings['bank_transfer']['case_insensitive']
		);
		$label = $ref_code ? '#' . $ref_code : '';

		if (!is_array($decoded)) {
			return "⚠️ Phản hồi không xác định (HTTP $http_code).";
		}

		$msg_text = '';
		if (isset($decoded['msg'])) {
			$msg_text = trim(is_array($decoded['msg']) ? implode(' ', $decoded['msg']) : (string) $decoded['msg']);
		}

		if (!empty($decoded['error'])) {
			if (stripos($msg_text, 'not found') !== false) {
				return "❌ Không tìm thấy yêu cầu thanh toán" . ($label ? " $label" : '') . '.';
			}
			if (stripos($msg_text, 'underpaid') !== false) {
				return "⚠️ Giao dịch $label thiếu tiền: nhận {$amount_fmt}.";
			}
			if (stripos($msg_text, 'cancel') !== false) {
				return "❌ Yêu cầu $label đã bị huỷ, không xử lý.";
			}
			return "⚠️ Chưa xác nhận được $label. " . ($msg_text ?: 'Có lỗi xảy ra.');
		}

		if (stripos($msg_text, 'processed before') !== false) {
			return "ℹ️ Giao dịch $label đã được xử lý trước đó.";
		}
		if (stripos($msg_text, 'overpaid') !== false) {
			return "✅ Xác nhận thanh toán $label - {$amount_fmt} (dư tiền).";
		}

		return "✅ Xác nhận thanh toán $label - {$amount_fmt} thành công.";
	}

	private function extract_telegram_text($payload)
	{
		if (!is_array($payload)) {
			return '';
		}

		$candidates = array(
			$payload['message']['text'] ?? '',
			$payload['message']['caption'] ?? '',
			$payload['channel_post']['text'] ?? '',
			$payload['channel_post']['caption'] ?? '',
			$payload['edited_message']['text'] ?? '',
			$payload['edited_message']['caption'] ?? '',
		);

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && trim($candidate) !== '') {
				return sanitize_textarea_field($candidate);
			}
		}

		return '';
	}

	private function extract_telegram_chat_id($payload)
	{
		if (!is_array($payload)) {
			return 0;
		}

		foreach (array('message', 'channel_post', 'edited_message') as $key) {
			if (isset($payload[$key]['chat']['id'])) {
				return intval($payload[$key]['chat']['id']);
			}
		}

		return 0;
	}

	private function send_telegram_reply($telegram_token, $chat_id, $message)
	{
		$telegram_token = trim((string) $telegram_token);
		$chat_id        = intval($chat_id);
		$message        = trim((string) $message);

		if ($telegram_token === '' || $chat_id <= 0 || $message === '') {
			return;
		}

		wp_remote_post('https://api.telegram.org/bot' . $telegram_token . '/sendMessage', array(
			'timeout' => 15,
			'body'    => array('chat_id' => $chat_id, 'text' => $message),
		));
	}

	private function extract_telegram_amount($text)
	{
		$text = (string) $text;
		if ($text === '') {
			return 0;
		}

		preg_match_all('/(?<!\d)(\d{1,3}(?:[\.,\s]\d{3})+|\d{4,12})(?!\d)/u', $text, $matches);
		if (empty($matches[1]) || !is_array($matches[1])) {
			return 0;
		}

		$max_amount = 0;
		foreach ($matches[1] as $raw) {
			$amount = intval(preg_replace('/[^0-9]/', '', (string) $raw));
			if ($amount > $max_amount) {
				$max_amount = $amount;
			}
		}

		return $max_amount;
	}

	/* ---------------------------------------------------------------------
	 * Tiện ích admin
	 * ------------------------------------------------------------------ */

	public function restore_masked_secrets()
	{
		if (!is_admin() || empty($_POST['settings'])) {
			return;
		}

		$settings = wp_unslash($_POST['settings']);
		$stored   = self::get_settings();

		// $_POST vẫn ở dạng slashed nên phải wp_slash() lại giá trị đọc từ DB.
		foreach (array('telegram_webhook_secret', 'tgs_hmac_secret') as $field) {
			if (isset($settings[$field]) && $settings[$field] === '***UNCHANGED***') {
				$_POST['settings'][$field] = wp_slash(isset($stored[$field]) ? $stored[$field] : '');
			}
		}
	}

	public function maybe_activation_redirect()
	{
		if (!get_transient('ttck_activation_redirect')) {
			return;
		}

		delete_transient('ttck_activation_redirect');

		if (is_network_admin() || isset($_GET['activate-multi']) || !current_user_can('manage_options')) {
			return;
		}

		wp_safe_redirect(admin_url('admin.php?page=ttck'));
		exit;
	}

	function load_plugin_textdomain()
	{
		load_plugin_textdomain($this->domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
	}
}

new TTCKPayment();
