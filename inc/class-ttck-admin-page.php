<?php
/**
 * Trang quản trị riêng của plugin (menu cấp 1 "Thanh toán QR").
 *
 * Trước đây phần cấu hình nằm rải ở hai nơi của WooCommerce:
 *   - WooCommerce → Thanh toán Quét Mã QR  (cấu hình chung)
 *   - WooCommerce → Cài đặt → Thanh toán   (bật/tắt + số tài khoản từng ngân hàng)
 * Cả hai đã gộp về đây thành các tab, không còn phụ thuộc WooCommerce.
 */

if (!defined('ABSPATH')) {
	exit;
}

class TTCK_Admin_Page
{
	const MENU_SLUG = 'ttck';

	/** @var string Thông báo hiển thị sau khi lưu */
	var $message = '';

	var $settings = array();

	public function __construct()
	{
		$this->settings = TTCKPayment::get_settings();

		$action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

		if ('ttck_save_settings' === $action) {
			$this->save_settings();
		} elseif ('ttck_save_banks' === $action) {
			$this->save_banks();
		} elseif ('ttck_save_vietinbank' === $action) {
			$this->save_vietinbank();
		} elseif ('ttck_reset_token' === $action) {
			$this->reset_secure_token();
		} elseif ('ttck_export_accounts' === $action) {
			$this->export_accounts();
		} elseif ('ttck_download_accounts' === $action) {
			$this->download_accounts();
		}

		add_action('admin_menu', array($this, 'register_menu'));

		/*
		 * AJAX test cho tab VietinBank — không reload trang khi bấm nút Test.
		 * Endpoint đăng ký ở đây (admin page class) thay vì TTCKPayment để giữ
		 * trách nhiệm sát với UI: render form → xử lý test.
		 */
		add_action('wp_ajax_ttck_test_vietinbank_login', array($this, 'ajax_test_vietinbank_login'));
		add_action('wp_ajax_ttck_test_vietinbank_search', array($this, 'ajax_test_vietinbank_search'));
		add_action('wp_ajax_ttck_vietinbank_list_transactions', array($this, 'ajax_vietinbank_list_transactions'));
	}

	public function register_menu()
	{
		add_menu_page(
			__('Thanh toán Quét Mã QR', 'thanh-toan-chuyen-khoan'),
			__('Thanh toán QR', 'thanh-toan-chuyen-khoan'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_page'),
			'dashicons-money-alt',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('Cài đặt chung', 'thanh-toan-chuyen-khoan'),
			__('Cài đặt chung', 'thanh-toan-chuyen-khoan'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_page')
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('Tài khoản ngân hàng', 'thanh-toan-chuyen-khoan'),
			__('Tài khoản ngân hàng', 'thanh-toan-chuyen-khoan'),
			'manage_options',
			'ttck-banks',
			array($this, 'render_page')
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('Giao dịch', 'thanh-toan-chuyen-khoan'),
			__('Giao dịch', 'thanh-toan-chuyen-khoan'),
			'manage_options',
			'ttck-transactions',
			array($this, 'render_page')
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('Xuất cấu hình', 'thanh-toan-chuyen-khoan'),
			__('Xuất cấu hình', 'thanh-toan-chuyen-khoan'),
			self::export_capability(),
			'ttck-export',
			array($this, 'render_page')
		);

		/*
		 * Tab VietinBank API — cùng quyền với Xuất cấu hình (manage_network_options
		 * trên multisite) vì chứa secret cấu hình ngân hàng. Đặt NGAY SAU Xuất
		 * cấu hình theo yêu cầu.
		 */
		add_submenu_page(
			self::MENU_SLUG,
			__('VietinBank API', 'thanh-toan-chuyen-khoan'),
			__('VietinBank API', 'thanh-toan-chuyen-khoan'),
			self::export_capability(),
			'ttck-vietinbank',
			array($this, 'render_page')
		);

		/*
		 * Tab "Quản lý giao dịch thanh toán" — bảng giao dịch VietinBank MAP
		 * Merchant Portal với đầy đủ 12 cột (thời gian, số tiền, mã GD, khách
		 * hàng, số TK, mã VA, chi nhánh, điểm bán, phương thức, nội dung GD,
		 * trạng thái, chi tiết). Lấy dữ liệu trực tiếp từ API search, không
		 * lưu DB — vì dữ liệu có hạn 5 phút từ VietinBank.
		 */
		add_submenu_page(
			self::MENU_SLUG,
			__('Quản lý giao dịch thanh toán', 'thanh-toan-chuyen-khoan'),
			__('Quản lý giao dịch thanh toán', 'thanh-toan-chuyen-khoan'),
			self::export_capability(),
			'ttck-vietinbank-transactions',
			array($this, 'render_page')
		);
	}

	/**
	 * Quyền cần có để vào màn "Xuất cấu hình".
	 *
	 * Cố ý HẸP HƠN quyền vào các tab khác, vì hai lẽ:
	 *
	 *   1. Xuất file là hành động CHỐT tài khoản nhận tiền của cả 650 shop.
	 *      Nếu admin từng shop cũng xuất được thì kịch bản tấn công rút còn
	 *      hai bước — sửa DB rồi bấm Xuất — và file lại khớp DB, mất sạch khả
	 *      năng phát hiện.
	 *   2. Bảng đối chiếu hiện tài khoản của MỌI shop. Admin một shop không có
	 *      việc gì phải nhìn thấy tài khoản của 649 shop còn lại.
	 *
	 * Site đơn (không multisite) thì manage_options đã là quyền cao nhất.
	 */
	private static function export_capability()
	{
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	private function can_export()
	{
		return current_user_can(self::export_capability());
	}

	/* ---------------------------------------------------------------------
	 * Lưu dữ liệu
	 * ------------------------------------------------------------------ */

	private function verify($nonce_action)
	{
		if (!current_user_can('manage_options')) {
			return false;
		}

		$nonce = isset($_REQUEST['ttck_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['ttck_nonce'])) : '';

		return (bool) wp_verify_nonce($nonce, $nonce_action);
	}

	private function error_message()
	{
		$this->message = '<div class="notice notice-error"><p><strong>'
			. esc_html__('Không lưu được cài đặt! Vui lòng tải lại trang.', 'thanh-toan-chuyen-khoan')
			. '</strong></p></div>';
	}

	private function saved_message()
	{
		$this->message = '<div class="notice notice-success"><p><strong>'
			. esc_html__('Đã lưu.', 'thanh-toan-chuyen-khoan')
			. '</strong></p></div>';
	}

	public function save_settings()
	{
		if (!$this->verify('ttck_save_settings')) {
			$this->error_message();
			return;
		}

		$posted   = ttck_recursive_sanitize_text_field(isset($_POST['settings']) ? wp_unslash($_POST['settings']) : array());
		$stored   = TTCKPayment::get_settings();
		$settings = $stored;

		$settings['bank_transfer']['enabled']          = !empty($posted['bank_transfer']['enabled']) ? 'yes' : 'no';
		$settings['bank_transfer']['viet_qr']          = !empty($posted['bank_transfer']['viet_qr']) ? 'yes' : 'no';
		$settings['bank_transfer']['case_insensitive'] = !empty($posted['bank_transfer']['case_insensitive']) ? 'yes' : 'no';

		$settings['bank_transfer']['transaction_prefix'] = ttck_clean_prefix((string) ($posted['bank_transfer']['transaction_prefix'] ?? ''));
		$settings['bank_transfer']['extra_text']         = remove_accents((string) ($posted['bank_transfer']['extra_text'] ?? ''));
		$settings['bank_transfer']['acceptable_difference'] = abs((int) ($posted['bank_transfer']['acceptable_difference'] ?? 0));

		$settings['qr_engine'] = in_array($posted['qr_engine'] ?? '', array('vietqr', 'local'), true)
			? $posted['qr_engine']
			: 'vietqr';

		$settings['payment_expire_minutes'] = max(1, min(1440, (int) ($posted['payment_expire_minutes'] ?? 30)));
		$settings['auto_check_status']      = !empty($posted['auto_check_status']) ? 1 : 0;

		foreach (array('telegram_token', 'telegram_chatid', 'telegram_webhook_secret', 'tgs_hmac_secret', 'webhook') as $field) {
			if (isset($posted[$field])) {
				$settings[$field] = (string) $posted[$field];
			}
		}

		// Secure token sinh một lần rồi giữ nguyên (app điện thoại đang dùng).
		if (strlen((string) $settings['bank_transfer']['secure_token']) <= 0) {
			$settings['bank_transfer']['secure_token'] = ttck_generate_random_string(16);
		}

		TTCKPayment::update_settings($settings);
		$this->settings = TTCKPayment::get_settings();
		$this->saved_message();
	}

	/**
	 * Lưu bảng tài khoản ngân hàng (thay cho màn hình gateway của WooCommerce).
	 */
	public function save_banks()
	{
		if (!$this->verify('ttck_save_banks')) {
			$this->error_message();
			return;
		}

		$rows = isset($_POST['bank']) && is_array($_POST['bank']) ? wp_unslash($_POST['bank']) : array();

		$settings = TTCKPayment::get_settings();
		$accounts = array();
		$meta     = array();
		$sort     = 0;

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$bank_id = strtolower(sanitize_text_field((string) ($row['bank_id'] ?? '')));
			$number  = sanitize_text_field((string) ($row['account_number'] ?? ''));
			$name    = sanitize_text_field((string) ($row['account_name'] ?? ''));

			if ($bank_id === '' || !TTCK_Banks::exists($bank_id) || $number === '') {
				continue;
			}

			// Cấu trúc mảng giữ nguyên như bản cũ để không phải migrate dữ liệu.
			$accounts[$bank_id] = array(
				array(
					'account_name'   => $name,
					'account_number' => $number,
					'bank_name'      => $bank_id,
				),
			);

			$meta[$bank_id] = array(
				'enabled' => !empty($row['enabled']) ? 'yes' : 'no',
				'title'   => sanitize_text_field((string) ($row['title'] ?? '')),
				'sort'    => $sort++,
			);
		}

		$settings['bank_transfer_accounts'] = $accounts;
		$settings['bank_meta']              = $meta;

		TTCKPayment::update_settings($settings);
		$this->settings = TTCKPayment::get_settings();

		/*
		 * Lưu DB xong KHÔNG có nghĩa là đã đổi được tài khoản khách quét.
		 * Lúc sinh QR hệ thống đọc file JSON đã chốt, không đọc DB. Nói thẳng
		 * ra đây, không thì người cấu hình tưởng xong việc rồi bỏ đi.
		 */
		$this->message = '<div class="notice notice-warning"><p><strong>'
			. esc_html__('Đã lưu vào cơ sở dữ liệu.', 'thanh-toan-chuyen-khoan')
			. '</strong> '
			. esc_html__('Cấu hình này CHƯA có hiệu lực: mã QR vẫn cấp theo file cấu hình đã chốt. Vào tab "Xuất cấu hình" để chốt lại.', 'thanh-toan-chuyen-khoan')
			. '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * VietinBank API
	 * ------------------------------------------------------------------ */

	/**
	 * Lưu cấu hình VietinBank từ form tab "VietinBank API".
	 *
	 * Chỉ 5 key lưu DB — đều required:
	 *   vietinbank_client_id, vietinbank_username, vietinbank_password_hash,
	 *   vietinbank_signature, vietinbank_merchant_id.
	 *
	 * 4 tham số còn lại (captcha_resp, ip_address, language, timeout) hardcode
	 * trong TTCK_VietinBank_API, KHÔNG có mặt trên form.
	 *
	 * Mật khẩu: form dùng input password đặt tên `settings[vietinbank_password]`
	 * (khác với key DB) — đây là chính sách đổi tên để đảm bảo plain text
	 * không bao giờ được ghi thẳng vào DB.
	 */
	public function save_vietinbank()
	{
		if (!$this->verify('ttck_save_vietinbank') || !$this->can_export()) {
			$this->error_message();
			return;
		}

		$posted = wp_unslash($_POST['settings'] ?? array());
		$stored = TTCKPayment::get_settings();
		$next   = $stored;

		$client_id   = sanitize_text_field($posted['vietinbank_client_id']   ?? '');
		$username    = sanitize_text_field($posted['vietinbank_username']    ?? '');
		$signature   = sanitize_text_field($posted['vietinbank_signature']   ?? '');
		$merchant_id = sanitize_text_field($posted['vietinbank_merchant_id'] ?? '');

		/*
		 * Validate 4 field required (password xử lý riêng bên dưới vì nó có
		 * thể để trống khi đã lưu hash từ lần trước).
		 */
		$missing = array();
		if ($client_id   === '') { $missing[] = 'Client ID'; }
		if ($username    === '') { $missing[] = 'Username'; }
		if ($signature   === '') { $missing[] = 'Signature'; }
		if ($merchant_id === '') { $missing[] = 'Merchant ID'; }

		if (!empty($missing)) {
			$this->message = '<div class="notice notice-error"><p><strong>'
				. esc_html__('Thiếu trường bắt buộc:', 'thanh-toan-chuyen-khoan')
				. '</strong> '
				. esc_html(implode(', ', $missing))
				. '</p></div>';
			return;
		}

		$next['vietinbank_client_id']   = $client_id;
		$next['vietinbank_username']    = $username;
		$next['vietinbank_signature']   = $signature;
		$next['vietinbank_merchant_id'] = $merchant_id;

		/*
		 * Mật khẩu — 3 nhánh:
		 *   1. Form có plain      → SHA-256, lưu hash.
		 *   2. Form trống + DB đã có hash → giữ hash cũ.
		 *   3. Form trống + DB chưa có   → lỗi, bắt buộc nhập lần đầu.
		 */
		$plain = (string) ($posted['vietinbank_password'] ?? '');
		if ($plain !== '') {
			$next['vietinbank_password_hash'] = hash('sha256', $plain);
		} elseif (empty($stored['vietinbank_password_hash'])) {
			$this->message = '<div class="notice notice-error"><p><strong>'
				. esc_html__('Chưa có mật khẩu.', 'thanh-toan-chuyen-khoan')
				. '</strong> '
				. esc_html__('Phải nhập mật khẩu lần đầu.', 'thanh-toan-chuyen-khoan')
				. '</p></div>';
			return;
		}

		TTCKPayment::update_settings($next);
		do_action('ttck_save_vietinbank_extra_settings', $posted);
		$this->settings = TTCKPayment::get_settings();
		$this->saved_message();
	}

/**
	 * Render tab VietinBank API — thiết kế gọn, đồng bộ:
	 *   - Form config nằm ngang, label 160px, status + required indicator
	 *     dùng class chung (không inline style).
	 *   - Mỗi API là một <details class="ttck-vtb-card">: URL hiển thị inline
	 *     trong summary, summary dùng ::before custom triangle (1 mũi tên, ẩn
	 *     marker mặc định của trình duyệt).
	 *   - Body mẫu / Response / Curl preview là <details class="ttck-vtb-nested">
	 *     lồng bên trong, gấp mở được.
	 *   - Test inputs dùng flex ngang, action row có dashed border-top ngăn
	 *     cách với phần test form.
	 *
	 * Config lưu DB (5 key required) hiển thị thành span data-* để JS đọc
	 * khi build curl — tránh gọi AJAX chỉ để lấy config.
	 */
	private function render_vietinbank_tab()
	{
		$settings      = $this->settings;
		$client_id     = (string) $settings['vietinbank_client_id'];
		$username      = (string) $settings['vietinbank_username'];
		$signature     = (string) $settings['vietinbank_signature'];
		$merchant_id   = (string) $settings['vietinbank_merchant_id'];
		$password_hash = (string) $settings['vietinbank_password_hash'];
		$has_password  = $password_hash !== '';
		?>
		<div class="ttck-vtb-wrap">

		<!-- Config ẩn — JS đọc để build curl, không tốn pixel. -->
		<span data-vtb-config="client_id"     data-vtb-value="<?php echo esc_attr($client_id); ?>"     hidden></span>
		<span data-vtb-config="username"      data-vtb-value="<?php echo esc_attr($username); ?>"      hidden></span>
		<span data-vtb-config="password_hash" data-vtb-value="<?php echo esc_attr($password_hash); ?>" hidden></span>
		<span data-vtb-config="signature"     data-vtb-value="<?php echo esc_attr($signature); ?>"     hidden></span>
		<span data-vtb-config="merchant_id"   data-vtb-value="<?php echo esc_attr($merchant_id); ?>"   hidden></span>

		<!-- ================== Cấu hình ================== -->
		<details class="ttck-vtb-card" open>
			<summary><?php esc_html_e('Cấu hình VietinBank API', 'thanh-toan-chuyen-khoan'); ?></summary>

			<form method="post">
				<input type="hidden" name="action" value="ttck_save_vietinbank">
				<input type="hidden" name="ttck_nonce" value="<?php echo esc_attr(wp_create_nonce('ttck_save_vietinbank')); ?>">

				<p class="ttck-vtb-small" style="margin-bottom:10px;">
					<?php esc_html_e('5 trường bắt buộc, không có default. Mật khẩu tự SHA-256 trước khi lưu — DB không bao giờ có plain text.', 'thanh-toan-chuyen-khoan'); ?>
				</p>

				<table class="ttck-vtb-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="vietinbank_client_id"><?php esc_html_e('Client ID', 'thanh-toan-chuyen-khoan'); ?><span class="ttck-vtb-required">*</span></label></th>
							<td>
								<input name="settings[vietinbank_client_id]" id="vietinbank_client_id" type="text"
									class="regular-text ttck-vtb-required-bar" value="<?php echo esc_attr($client_id); ?>" required>
								<small class="ttck-vtb-small">Header <code>ClientId</code> gửi lên VietinBank.</small>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vietinbank_username"><?php esc_html_e('Username', 'thanh-toan-chuyen-khoan'); ?><span class="ttck-vtb-required">*</span></label></th>
							<td>
								<input name="settings[vietinbank_username]" id="vietinbank_username" type="text"
									class="regular-text ttck-vtb-required-bar" value="<?php echo esc_attr($username); ?>" required>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vietinbank_password"><?php esc_html_e('Mật khẩu', 'thanh-toan-chuyen-khoan'); ?><span class="ttck-vtb-required">*</span></label></th>
							<td>
								<?php if ($has_password): ?>
									<span class="ttck-vtb-status-ok"><?php esc_html_e('Đã lưu SHA-256 hash (64 hex)', 'thanh-toan-chuyen-khoan'); ?></span>
									<label class="ttck-vtb-inline-toggle">
										<input type="checkbox" data-toggle-target="vietinbank_password_input">
										<span><?php esc_html_e('đổi mật khẩu', 'thanh-toan-chuyen-khoan'); ?></span>
									</label>
									<input type="password" id="vietinbank_password_input"
										class="ttck-vtb-conditional"
										name="settings[vietinbank_password]"
										placeholder="<?php esc_attr_e('Mật khẩu mới...', 'thanh-toan-chuyen-khoan'); ?>"
										autocomplete="new-password">
								<?php else: ?>
									<input name="settings[vietinbank_password]" id="vietinbank_password" type="password"
										class="regular-text ttck-vtb-required-bar" value="" required
										placeholder="<?php esc_attr_e('Nhập mật khẩu', 'thanh-toan-chuyen-khoan'); ?>"
										autocomplete="new-password">
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vietinbank_signature"><?php esc_html_e('Signature', 'thanh-toan-chuyen-khoan'); ?><span class="ttck-vtb-required">*</span></label></th>
							<td>
								<input name="settings[vietinbank_signature]" id="vietinbank_signature" type="text"
									class="regular-text ttck-vtb-required-bar" value="<?php echo esc_attr($signature); ?>" required>
								<small class="ttck-vtb-small">Header <code>Signature</code> — có thể đổi theo phiên, hỏi VietinBank nếu không biết.</small>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vietinbank_merchant_id"><?php esc_html_e('Merchant ID', 'thanh-toan-chuyen-khoan'); ?><span class="ttck-vtb-required">*</span></label></th>
							<td>
								<input name="settings[vietinbank_merchant_id]" id="vietinbank_merchant_id" type="text"
									class="regular-text ttck-vtb-required-bar" value="<?php echo esc_attr($merchant_id); ?>" required>
								<small class="ttck-vtb-small">Header <code>merchantId</code> — chỉ dùng cho API truy vấn.</small>
							</td>
						</tr>
						<?php do_action('ttck_vietinbank_settings_rows'); ?>
					</tbody>
				</table>

				<div class="ttck-vtb-save-bar">
					<button type="submit" class="button button-primary"><?php esc_html_e('Lưu cấu hình', 'thanh-toan-chuyen-khoan'); ?></button>
				</div>
			</form>
		</details>

		<!-- ================== API 1: Login ================== -->
		<details class="ttck-vtb-card" open>
			<summary>
				<span>1. <?php esc_html_e('Đăng nhập', 'thanh-toan-chuyen-khoan'); ?></span>
				<span class="ttck-vtb-summary-url">POST <?php echo esc_html(TTCK_VietinBank_API::LOGIN_URL); ?></span>
			</summary>

			<details class="ttck-vtb-nested">
				<summary><?php esc_html_e('Body mẫu + Headers', 'thanh-toan-chuyen-khoan'); ?></summary>
				<p class="ttck-vtb-small" style="margin-bottom:8px;">
					<strong>Headers:</strong> <code>Content-Type</code>, <code>Accept</code>, <code>ClientId</code> (config), <code>Signature</code> (config), <code>Origin</code>, <code>Referer</code>, <code>User-Agent</code>
				</p>
				<pre class="ttck-vtb-body-sample"><code>{
  "username":     "&lt;lấy từ Cấu hình&gt;",
  "password":     "&lt;SHA-256 hash từ Cấu hình&gt;",
  "captcha_resp": "&lt;nhập bên dưới&gt;",
  "device":       { "os": {"name":null,"version":null}, "browser": {"name":null,"version":null}, "location": {"long":0,"lat":0} },
  "ip_address":   "&lt;nhập bên dưới&gt;",
  "language":     "&lt;nhập bên dưới&gt;"
}</code></pre>
			</details>

			<h4 style="margin-bottom:8px;"><?php esc_html_e('Test trực tiếp', 'thanh-toan-chuyen-khoan'); ?></h4>
			<form class="ttck-vtb-test-form">
				<label>
					<span>captcha_resp</span>
					<input type="text" class="ttck-vtb-narrow" data-vtb-field="captcha_resp"
						value="<?php echo esc_attr(TTCK_VietinBank_API::DEFAULT_CAPTCHA_RESP); ?>">
				</label>
				<label>
					<span>ip_address</span>
					<input type="text" class="ttck-vtb-wide" data-vtb-field="ip_address"
						value="<?php echo esc_attr(TTCK_VietinBank_API::DEFAULT_IP_ADDRESS); ?>">
				</label>
				<label>
					<span>language</span>
					<select data-vtb-field="language">
						<option value="vi" <?php selected(TTCK_VietinBank_API::DEFAULT_LANGUAGE, 'vi'); ?>>vi</option>
						<option value="en" <?php selected(TTCK_VietinBank_API::DEFAULT_LANGUAGE, 'en'); ?>>en</option>
					</select>
				</label>
			</form>

			<div class="ttck-vtb-actions">
				<button type="button" class="button button-primary ttck-vtb-test-login"><?php esc_html_e('Test login', 'thanh-toan-chuyen-khoan'); ?></button>
				<button type="button" class="button ttck-vtb-copy-curl-login"><?php esc_html_e('Copy curl', 'thanh-toan-chuyen-khoan'); ?></button>
				<span class="ttck-vtb-hint"><?php esc_html_e('Token lưu transient 1 giờ — Test search dùng lại được.', 'thanh-toan-chuyen-khoan'); ?></span>
			</div>

			<details class="ttck-vtb-nested ttck-vtb-response-wrap" hidden>
				<summary><?php esc_html_e('Response', 'thanh-toan-chuyen-khoan'); ?></summary>
				<div class="ttck-vtb-response"></div>
			</details>
			<details class="ttck-vtb-nested ttck-vtb-curl-wrap" hidden>
				<summary><?php esc_html_e('Curl preview', 'thanh-toan-chuyen-khoan'); ?></summary>
				<textarea class="ttck-vtb-curl-preview" readonly></textarea>
			</details>
		</details>

		<!-- ================== API 2: Search ================== -->
		<details class="ttck-vtb-card" open>
			<summary>
				<span>2. <?php esc_html_e('Truy vấn giao dịch', 'thanh-toan-chuyen-khoan'); ?></span>
				<span class="ttck-vtb-summary-url">POST <?php echo esc_html(TTCK_VietinBank_API::SEARCH_URL); ?>?page=0&amp;size=20&amp;sort=txnDate,desc</span>
			</summary>

			<details class="ttck-vtb-nested">
				<summary><?php esc_html_e('Body mẫu + Headers', 'thanh-toan-chuyen-khoan'); ?></summary>
				<p class="ttck-vtb-small" style="margin-bottom:8px;">
					<strong>Headers:</strong> <code>Authorization: Bearer &lt;accessToken&gt;</code>, <code>ClientId</code> (config), <code>merchantId</code> (config), <code>x-lang</code>, <code>Content-Type</code>, <code>Accept</code>, <code>Origin</code>, <code>Referer</code>, <code>User-Agent</code>
				</p>
				<pre class="ttck-vtb-body-sample"><code>{
  "searchType": "&lt;nhập bên dưới&gt;",
  "startDate":  "&lt;dd/MM/yyyy&gt;",
  "endDate":    "&lt;dd/MM/yyyy&gt;"
}</code></pre>
			</details>

			<h4 style="margin-bottom:8px;"><?php esc_html_e('Test trực tiếp', 'thanh-toan-chuyen-khoan'); ?></h4>
			<form class="ttck-vtb-test-form">
				<label>
					<span>Từ ngày</span>
					<input type="text" class="ttck-vtb-narrow" data-vtb-field="start_date"
						value="<?php echo esc_attr(date('d/m/Y')); ?>" placeholder="dd/MM/yyyy" required>
				</label>
				<label>
					<span>Đến ngày</span>
					<input type="text" class="ttck-vtb-narrow" data-vtb-field="end_date"
						value="<?php echo esc_attr(date('d/m/Y')); ?>" placeholder="dd/MM/yyyy" required>
				</label>
				<label>
					<span>searchType</span>
					<select data-vtb-field="search_type">
						<option value="0">0 — <?php esc_html_e('Tất cả', 'thanh-toan-chuyen-khoan'); ?></option>
						<option value="1">1 — <?php esc_html_e('Đã ghi có', 'thanh-toan-chuyen-khoan'); ?></option>
					</select>
				</label>
				<label>
					<span>page</span>
					<input type="number" min="0" class="ttck-vtb-narrow" data-vtb-field="page" value="0">
				</label>
				<label>
					<span>size</span>
					<input type="number" min="1" max="200" class="ttck-vtb-narrow" data-vtb-field="size" value="20">
				</label>
				<label>
					<span>sort</span>
					<input type="text" class="ttck-vtb-wide" data-vtb-field="sort" value="txnDate,desc">
				</label>
				<label>
					<span>language</span>
					<select data-vtb-field="language">
						<option value="vi" <?php selected(TTCK_VietinBank_API::DEFAULT_LANGUAGE, 'vi'); ?>>vi</option>
						<option value="en" <?php selected(TTCK_VietinBank_API::DEFAULT_LANGUAGE, 'en'); ?>>en</option>
					</select>
				</label>
			</form>

			<div class="ttck-vtb-actions">
				<button type="button" class="button button-primary ttck-vtb-test-search"><?php esc_html_e('Test search', 'thanh-toan-chuyen-khoan'); ?></button>
				<button type="button" class="button ttck-vtb-copy-curl-search"><?php esc_html_e('Copy curl', 'thanh-toan-chuyen-khoan'); ?></button>
				<span class="ttck-vtb-hint"><?php esc_html_e('Yêu cầu đã Test login trước.', 'thanh-toan-chuyen-khoan'); ?></span>
			</div>

			<details class="ttck-vtb-nested ttck-vtb-response-wrap" hidden>
				<summary><?php esc_html_e('Response', 'thanh-toan-chuyen-khoan'); ?></summary>
				<div class="ttck-vtb-response"></div>
			</details>
			<details class="ttck-vtb-nested ttck-vtb-curl-wrap" hidden>
				<summary><?php esc_html_e('Curl preview', 'thanh-toan-chuyen-khoan'); ?></summary>
				<textarea class="ttck-vtb-curl-preview" readonly></textarea>
			</details>
		</details>

		<p class="ttck-vtb-small" style="margin-top:14px;">
			<strong><?php esc_html_e('Lưu ý:', 'thanh-toan-chuyen-khoan'); ?></strong>
			<?php esc_html_e('captcha_resp / ip_address / language / timeout không lưu DB — dùng default trong TTCK_VietinBank_API khi caller không truyền. Code khác có thể override.', 'thanh-toan-chuyen-khoan'); ?>
		</p>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// AJAX test cho tab VietinBank
	// ---------------------------------------------------------------------

	/**
	 * Test API 1: login. Lấy cấu hình từ DB, nhận 3 field runtime từ form test
	 * (captcha_resp, ip_address, language), gọi TTCK_VietinBank_API::login().
	 * Token trả về lưu vào transient 1 giờ để test search dùng lại — đỡ phải
	 * login lại mỗi lần bấm Test search.
	 */
	public function ajax_test_vietinbank_login()
	{
		check_ajax_referer('ttck_test_vietinbank', 'nonce');

		if (!$this->can_export()) {
			wp_send_json_error(array('message' => 'Không có quyền.'), 403);
		}

		$settings = TTCKPayment::get_settings();

		$required = array(
			'vietinbank_client_id'     => 'Client ID',
			'vietinbank_username'      => 'Username',
			'vietinbank_password_hash' => 'Mật khẩu (đã hash)',
			'vietinbank_signature'     => 'Signature',
			'vietinbank_merchant_id'   => 'Merchant ID',
		);
		$missing = array();
		foreach ($required as $key => $label) {
			if (empty($settings[$key])) {
				$missing[] = $label;
			}
		}
		if (!empty($missing)) {
			wp_send_json_error(array(
				'message' => 'Chưa cấu hình đủ: ' . implode(', ', $missing) . '. Vào tab "VietinBank API" → Lưu cấu hình trước.',
			));
		}

		$body = wp_unslash($_POST['body'] ?? array());
		$api  = new TTCK_VietinBank_API();
		$resp = $api->login(array(
			'client_id'     => $settings['vietinbank_client_id'],
			'signature'     => $settings['vietinbank_signature'],
			'username'      => $settings['vietinbank_username'],
			'password_hash' => $settings['vietinbank_password_hash'],
			'captcha_resp'  => sanitize_text_field($body['captcha_resp'] ?? TTCK_VietinBank_API::DEFAULT_CAPTCHA_RESP),
			'ip_address'    => sanitize_text_field($body['ip_address']   ?? TTCK_VietinBank_API::DEFAULT_IP_ADDRESS),
			'language'      => sanitize_text_field($body['language']     ?? TTCK_VietinBank_API::DEFAULT_LANGUAGE),
		));

		if (!$resp['ok']) {
			wp_send_json_error(array(
				'message'  => 'Đăng nhập thất bại: ' . ($resp['error'] ?: ('HTTP ' . $resp['status'])),
				'response' => $resp,
			));
		}

		/*
		 * Rút accessToken ra — thử nhiều tên key phổ biến vì schema VietinBank
		 * có thể đổi theo phiên. Tìm được thì cache vào TRANSIENT_TOKEN với
		 * expires_at để helper get_token() tự refresh khi sắp hết hạn.
		 */
		$access_token = '';
		if (is_array($resp['data'])) {
			foreach (array('accessToken', 'access_token', 'token', 'id_token', 'jsonWebToken') as $k) {
				if (!empty($resp['data'][$k]) && is_string($resp['data'][$k])) {
					$access_token = $resp['data'][$k];
					break;
				}
			}
		}

		$token_preview = '';
		if ($access_token !== '') {
			$expires_in = 0;
			if (is_array($resp['data'])) {
				foreach (array('expiresIn', 'expires_in', 'exp') as $k) {
					if (!empty($resp['data'][$k])) {
						$expires_in = (int) $resp['data'][$k];
						break;
					}
				}
			}
			if ($expires_in <= 0) {
				$expires_in = 3600;
			}
			set_transient(TTCK_VietinBank_API::TOKEN_TRANSIENT, [
				'access_token' => $access_token,
				'expires_at'   => time() + $expires_in,
				'saved_at'     => time(),
			], TTCK_VietinBank_API::TOKEN_TTL);
			$token_preview = substr($access_token, 0, 40) . '…';
		}

		wp_send_json_success(array(
			'message'       => 'Đăng nhập thành công.' . ($token_preview !== '' ? ' Đã lưu token để test search.' : ' Không tìm thấy token trong response — kiểm tra schema.'),
			'response'      => $resp,
			'token_preview' => $token_preview,
		));
	}

	/**
	 * Test API 2: payment-transaction/search. Lấy token từ transient (do ajax_test_vietinbank_login
	 * set trước đó). Nếu chưa có → báo phải login trước. Body 3 field runtime
	 * (searchType, startDate, endDate) + query 3 field (page, size, sort) + 2
	 * field runtime (language).
	 */
	public function ajax_test_vietinbank_search()
	{
		check_ajax_referer('ttck_test_vietinbank', 'nonce');

		if (!$this->can_export()) {
			wp_send_json_error(array('message' => 'Không có quyền.'), 403);
		}

		$settings = TTCKPayment::get_settings();
		foreach (array('vietinbank_client_id', 'vietinbank_merchant_id') as $key) {
			if (empty($settings[$key])) {
				wp_send_json_error(array(
					'message' => 'Chưa cấu hình ' . $key . '. Vào tab "VietinBank API" → Lưu cấu hình trước.',
				));
			}
		}

		/*
		 * Lấy token tự động refresh — helper xử lý cache miss + sắp hết hạn.
		 * Nếu có 401 → xoá cache + gọi lại search 1 lần duy nhất.
		 */
		$token = TTCK_VietinBank_API::get_token($settings);
		if (is_wp_error($token)) {
			wp_send_json_error(array('message' => $token->get_error_message()));
		}

		$body = wp_unslash($_POST['body'] ?? array());
		$start = sanitize_text_field($body['start_date'] ?? '');
		$end   = sanitize_text_field($body['end_date']   ?? '');
		if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $start) || !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $end)) {
			wp_send_json_error(array('message' => 'Ngày phải theo định dạng dd/MM/yyyy.'));
		}

		$api  = new TTCK_VietinBank_API();
		$args = array(
			'token'       => $token,
			'client_id'   => $settings['vietinbank_client_id'],
			'merchant_id' => $settings['vietinbank_merchant_id'],
			'start_date'  => $start,
			'end_date'    => $end,
			'search_type' => sanitize_text_field($body['search_type'] ?? '0'),
			'page'        => max(0, intval($body['page'] ?? 0)),
			'size'        => max(1, min(200, intval($body['size'] ?? 20))),
			'sort'        => sanitize_text_field($body['sort'] ?? 'txnDate,desc'),
			'language'    => sanitize_text_field($body['language'] ?? TTCK_VietinBank_API::DEFAULT_LANGUAGE),
		);
		$resp = $api->search_transactions($args);

		/*
		 * Retry-on-401: nếu cached token thực sự đã expired (VietinBank TTL
		 * không khớp expires_at) → xoá cache, login lại, gọi lại search.
		 */
		if (!$resp['ok'] && (int) $resp['status'] === 401) {
			TTCK_VietinBank_API::clear_token();
			$token = TTCK_VietinBank_API::get_token($settings);
			if (!is_wp_error($token)) {
				$args['token'] = $token;
				$resp = $api->search_transactions($args);
			}
		}

		if (!$resp['ok']) {
			wp_send_json_error(array(
				'message'  => 'Truy vấn thất bại: ' . ($resp['error'] ?: ('HTTP ' . $resp['status'])),
				'response' => $resp,
			));
		}

		wp_send_json_success(array(
			'message'  => 'Truy vấn thành công.',
			'response' => $resp,
			'token_preview' => substr($token, 0, 40) . '…',
		));
	}

	/**
	 * AJAX: lấy danh sách giao dịch VietinBank cho tab "Quản lý giao dịch".
	 *
	 * Input: start_date, end_date (dd/MM/yyyy), page, size.
	 * Output: danh sách GD + phân trang.
	 *
	 * KHÔNG cache DB — dữ liệu lấy thẳng từ API VietinBank. Token cache dùng
	 * chung với tab VietinBank API (transient `ttck_vtb_token`, TTL 1h).
	 */
	public function ajax_vietinbank_list_transactions()
	{
		check_ajax_referer('ttck_vietinbank_list', 'nonce');

		if (!$this->can_export()) {
			wp_send_json_error(['message' => 'Không có quyền.'], 403);
		}

		$start = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
		$end   = isset($_POST['end_date'])   ? sanitize_text_field(wp_unslash($_POST['end_date']))   : '';
		$page  = isset($_POST['page']) ? max(0, intval($_POST['page'])) : 0;
		$size  = isset($_POST['size']) ? max(1, min(100, intval($_POST['size']))) : 20;

		// Chấp nhận cả dd/MM/yyyy (form cũ / API caller) và yyyy-MM-dd (HTML5 date input) → chuẩn hoá về dd/MM/yyyy.
		$start = $this->normalize_date_input($start);
		$end   = $this->normalize_date_input($end);

		if ($start === '' || $end === '') {
			wp_send_json_error(['message' => 'Ngày phải theo định dạng dd/MM/yyyy hoặc yyyy-MM-dd.']);
		}
		if (strtotime($start) > strtotime($end)) {
			wp_send_json_error(['message' => '"Từ ngày" phải nhỏ hơn hoặc bằng "Đến ngày".']);
		}

		$s = TTCKPayment::get_settings();
		foreach (['vietinbank_client_id', 'vietinbank_signature', 'vietinbank_password_hash', 'vietinbank_merchant_id'] as $required) {
			if (empty($s[$required])) {
				wp_send_json_error(['message' => 'Chưa cấu hình VietinBank (' . $required . ') vào tab VietinBank API.']);
			}
		}

		/*
		 * Token tự động refresh qua helper:
		 *   - Cache miss → login
		 *   - Sắp hết hạn (< TOKEN_REFRESH_BEFORE giây) → login lại
		 *   - Vẫn fail → retry-on-401 bên dưới.
		 */
		$token = TTCK_VietinBank_API::get_token($s);
		if (is_wp_error($token)) {
			wp_send_json_error(['message' => $token->get_error_message()]);
		}

		$api = new TTCK_VietinBank_API();
		$args = [
			'token'       => $token,
			'client_id'   => $s['vietinbank_client_id'],
			'merchant_id' => $s['vietinbank_merchant_id'],
			'start_date'  => $start,
			'end_date'    => $end,
			'page'        => $page,
			'size'        => $size,
			'sort'        => 'tranTime,desc',
			'language'    => TTCK_VietinBank_API::DEFAULT_LANGUAGE,
		];
		$resp = $api->search_transactions($args);

		/*
		 * Retry-on-401: nếu cached token thực sự expired (expires_at sai
		 * hoặc VietinBank TTL ngắn hơn) → xoá + login lại + gọi lại search.
		 */
		if (!$resp['ok'] && (int) $resp['status'] === 401) {
			TTCK_VietinBank_API::clear_token();
			$token = TTCK_VietinBank_API::get_token($s);
			if (!is_wp_error($token)) {
				$args['token'] = $token;
				$resp = $api->search_transactions($args);
			}
		}

		if (!$resp['ok']) {
			wp_send_json_error(['message' => 'VietinBank search fail: ' . ($resp['error'] ?? 'unknown')]);
		}

		$list = $this->extract_transaction_list($resp['data'] ?? null);

		wp_send_json_success([
			'message' => 'Lấy danh sách giao dịch thành công.',
			'rows'    => $list,
			'total'   => isset($resp['data']['total']) ? intval($resp['data']['total']) : count($list),
			'page'    => $page,
			'size'    => $size,
		]);
	}

/**
	 * Rút mảng giao dịch từ response VietinBank MAP — API không chuẩn hoá tên
	 * key chứa list (đã thấy `list`, `content`, `data`, và các key khác tuỳ
	 * phiên; lại còn lồng 2 lớp `{code, message, data: {…, list: […]}}`).
	 * Thử các key phổ biến ở mọi cấp trước (fast path), nếu trượt thì quét
	 * toàn bộ response tìm array of object có field đặc trưng của giao dịch.
	 *
	 * @param mixed $data Body JSON đã decode từ VietinBank.
	 * @return array      Mảng giao dịch (rỗng nếu không tìm thấy).
	 */
	private function extract_transaction_list($data)
	{
		if (!is_array($data)) {
			return [];
		}

		$preferred = ['list', 'content', 'data', 'transactions', 'paymentTransactions', 'items', 'records', 'rows', 'payment_transactions'];
		$signatures = ['tranTime', 'transactionNumber', 'reqCardName', 'virtualAccount', 'transactionDescription'];

		// BFS qua các object lồng nhau, thử preferred key ở mỗi cấp.
		$queue = [$data];
		while ($queue) {
			$node = array_shift($queue);
			if (!is_array($node)) {
				continue;
			}
			foreach ($preferred as $k) {
				if (!empty($node[$k]) && is_array($node[$k]) && isset($node[$k][0]) && is_array($node[$k][0])) {
					return $node[$k];
				}
			}
			foreach ($node as $v) {
				if (is_array($v) && !empty($v) && array_keys($v) !== range(0, count($v) - 1)) {
					$queue[] = $v;
				}
			}
		}

		// Fallback toàn response: tìm array of object có field signature.
		$found = null;
		$this->find_transaction_list_recursive($data, $signatures, $found);
		return $found ?? [];
	}

	/**
	 * Chuẩn hoá input ngày về định dạng dd/MM/yyyy (chuẩn VietinBank yêu cầu).
	 * Chấp nhận: dd/MM/yyyy | yyyy-MM-dd | yyyy/MM/dd. Trả về '' nếu không hợp lệ.
	 */
	private function normalize_date_input($raw)
	{
		$raw = trim((string) $raw);
		if ($raw === '') {
			return '';
		}
		if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
			if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
				return $m[1] . '/' . $m[2] . '/' . $m[3];
			}
			return '';
		}
		if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/', $raw, $m)) {
			if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
				return $m[3] . '/' . $m[2] . '/' . $m[1];
			}
			return '';
		}
		return '';
	}

	/**
	 * Đệ quy tìm mảng giao dịch: first match array of object có ≥1 field signature.
	 *
	 * @param mixed $node       Node hiện tại (sẽ bị thay đổi nếu là reference, an toàn vì dùng biến cục bộ).
	 * @param array $signatures Danh sách field đặc trưng của giao dịch.
	 * @param array|null &$out  Lưu kết quả khi tìm thấy.
	 */
	private function find_transaction_list_recursive($node, array $signatures, &$out)
	{
		if ($out !== null || !is_array($node)) {
			return;
		}
		// Nếu là list-of-assoc-arrays có field signature → match.
		if (isset($node[0]) && is_array($node[0])) {
			foreach ($signatures as $sig) {
				if (array_key_exists($sig, $node[0])) {
					$out = $node;
					return;
				}
			}
			return;
		}
		foreach ($node as $v) {
			if ($out !== null) {
				return;
			}
			if (is_array($v)) {
				$this->find_transaction_list_recursive($v, $signatures, $out);
			}
		}
	}

	/**
	 * Render tab "Quản lý giao dịch thanh toán" — bảng giao dịch VietinBank
	 * MAP với 12 cột (thời gian, số tiền, mã GD, khách hàng, số TK, mã VA,
	 * chi nhánh, điểm bán, phương thức, nội dung, trạng thái, chi tiết).
	 * Tải qua AJAX `ttck_vietinbank_list_transactions` — không cache DB.
	 */
	private function render_vietinbank_transactions_tab()
	{
		?>
		<div class="ttck-vtx-wrap">
			<h2><?php esc_html_e('Quản lý giao dịch thanh toán', 'thanh-toan-chuyen-khoan'); ?></h2>
			<p class="description">
				<?php esc_html_e('Tra cứu giao dịch VietinBank MAP Merchant Portal theo khoảng ngày. Dữ liệu lấy trực tiếp từ API VietinBank, không lưu DB.', 'thanh-toan-chuyen-khoan'); ?>
			</p>

			<form class="ttck-vtx-filter" data-module="vietinbank-transactions">
				<label>
					<span><?php esc_html_e('Từ ngày', 'thanh-toan-chuyen-khoan'); ?></span>
					<input type="date" name="start_date" id="ttck-vtx-start" class="ttck-vtx-date" value="<?php echo esc_attr(date('Y-m-d', strtotime('-7 days'))); ?>" max="<?php echo esc_attr(date('Y-m-d')); ?>" required>
				</label>
				<label>
					<span><?php esc_html_e('Đến ngày', 'thanh-toan-chuyen-khoan'); ?></span>
					<input type="date" name="end_date" id="ttck-vtx-end" class="ttck-vtx-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" max="<?php echo esc_attr(date('Y-m-d')); ?>" required>
				</label>
				<label>
					<span><?php esc_html_e('Số dòng / trang', 'thanh-toan-chuyen-khoan'); ?></span>
					<select name="size" class="ttck-vtx-size">
						<option value="10">10</option>
						<option value="20" selected>20</option>
						<option value="50">50</option>
						<option value="100">100</option>
					</select>
				</label>
				<input type="hidden" name="page" value="0" class="ttck-vtx-page">
				<button type="submit" class="button button-primary ttck-vtx-search">
					<?php esc_html_e('Tìm kiếm', 'thanh-toan-chuyen-khoan'); ?>
				</button>
			</form>

			<div class="ttck-vtx-summary tgs-ba-muted" hidden></div>

			<nav class="ttck-vtx-pagination" hidden aria-label="<?php esc_attr_e('Phân trang', 'thanh-toan-chuyen-khoan'); ?>"></nav>

			<div class="ttck-vtx-table-wrap">
				<table class="ttck-vtx-table">
					<thead>
						<tr>
							<th class="ttck-vtx-col-time ttck-vtx-sticky-left"><?php esc_html_e('Thời gian giao dịch', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-amount"><?php esc_html_e('Số tiền', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-code"><?php esc_html_e('Mã giao dịch', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-customer"><?php esc_html_e('Thông tin khách hàng', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-account"><?php esc_html_e('Số TK / Số thẻ', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-va"><?php esc_html_e('Mã định danh tài khoản', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-branch"><?php esc_html_e('Tên Chi nhánh', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-terminal"><?php esc_html_e('Tên Điểm bán', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-method"><?php esc_html_e('Phương thức thanh toán', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-desc"><?php esc_html_e('Nội dung giao dịch', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-status ttck-vtx-sticky-right"><?php esc_html_e('Trạng thái', 'thanh-toan-chuyen-khoan'); ?></th>
							<th class="ttck-vtx-col-action ttck-vtx-sticky-right"><?php esc_html_e('Chi tiết', 'thanh-toan-chuyen-khoan'); ?></th>
						</tr>
					</thead>
					<tbody class="ttck-vtx-body">
						<tr><td colspan="12" class="ttck-vtx-empty"><?php esc_html_e('Bấm "Tìm kiếm" để tải danh sách giao dịch.', 'thanh-toan-chuyen-khoan'); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Xuất file tài khoản
	 * ------------------------------------------------------------------ */

	/** Ghi file JSON đè lên bản cũ */
	private function export_accounts()
	{
		if (!$this->verify('ttck_export_accounts') || !$this->can_export()) {
			$this->error_message();
			return;
		}

		$result = TTCK_Account_File::export();

		if (is_wp_error($result)) {
			$this->message = '<div class="notice notice-error"><p><strong>'
				. esc_html__('Không xuất được file:', 'thanh-toan-chuyen-khoan') . '</strong> '
				. esc_html($result->get_error_message())
				. '</p></div>';
			return;
		}

		$this->message = '<div class="notice notice-success"><p><strong>'
			. esc_html(sprintf(
				/* translators: 1: số shop, 2: số tài khoản, 3: dung lượng */
				__('Đã chốt cấu hình: %1$d shop, %2$d tài khoản (%3$s).', 'thanh-toan-chuyen-khoan'),
				$result['shop_count'],
				$result['account_count'],
				size_format($result['bytes'])
			))
			. '</strong> '
			. esc_html__('Nhớ khoá lại file ở server.', 'thanh-toan-chuyen-khoan')
			. '</p></div>';
	}

	/**
	 * Tải JSON về máy mà KHÔNG ghi đè file đang chạy.
	 *
	 * Cần cho hai việc: xem trước cấu hình sắp chốt, và lấy bản sao lưu khi
	 * file trên server đang khoá cứng không ghi được.
	 */
	private function download_accounts()
	{
		if (!$this->verify('ttck_export_accounts') || !$this->can_export()) {
			$this->error_message();
			return;
		}

		$json = TTCK_Account_File::build_json();
		if ($json === false) {
			$this->error_message();
			return;
		}

		/*
		 * ── VỨT SẠCH MỌI THỨ ĐÃ TRÓT IN RA TRƯỚC ĐÓ ─────────────────────
		 *
		 * Đã dính đúng một lần: một plugin khác lưu file kèm BOM UTF-8, nên
		 * PHP in ra 3 byte rác (EF BB BF) ở mọi request. Chúng lọt vào đầu
		 * file tải về, và vì Content-Length khai theo strlen($json) nên
		 * trình duyệt cắt mất đúng 3 byte CUỐI — file JSON thiếu hai dấu
		 * ngoặc đóng, mở lên là lỗi cú pháp.
		 *
		 * Cái BOM đó đã gỡ, nhưng bất kỳ notice hay khoảng trắng nào của
		 * plugin khác cũng gây lại y hệt. Nên dọn sạch bộ đệm ngay tại đây
		 * thay vì tin rằng không ai in gì.
		 *
		 * Và bỏ luôn Content-Length: thiếu nó thì trình duyệt đọc tới hết
		 * luồng, không có gì để cắt nhầm. File cấu hình vài KB, không cần
		 * thanh tiến trình.
		 */
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="bank-accounts-' . gmdate('Ymd-His') . '.json"');

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- tải file JSON thô
		exit;
	}

	public function reset_secure_token()
	{
		if (!$this->verify('ttck_reset_token')) {
			$this->error_message();
			return;
		}

		$settings = TTCKPayment::get_settings();
		$settings['bank_transfer']['secure_token'] = ttck_generate_random_string(16);
		TTCKPayment::update_settings($settings);

		$this->settings = TTCKPayment::get_settings();
		$this->message  = '<div class="notice notice-warning"><p><strong>'
			. esc_html__('Đã tạo Secure Token mới. Nhớ quét lại mã QR bằng app trên điện thoại.', 'thanh-toan-chuyen-khoan')
			. '</strong></p></div>';
	}

	/* ---------------------------------------------------------------------
	 * Hiển thị
	 * ------------------------------------------------------------------ */

	public function render_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::MENU_SLUG;

		echo '<div class="wrap ttck-admin">';
		echo '<h1>' . esc_html__('Thanh toán Quét Mã QR', 'thanh-toan-chuyen-khoan') . '</h1>';
		echo '<p class="description">'
			. esc_html__('Sinh mã QR động và tự động xác nhận chuyển khoản. Plugin chạy độc lập, không cần WooCommerce.', 'thanh-toan-chuyen-khoan')
			. '</p>';

		$this->render_tabs($page);

		echo wp_kses_post($this->message);

		switch ($page) {
			case 'ttck-banks':
				$this->render_banks_tab();
				break;
			case 'ttck-transactions':
				$this->render_transactions_tab();
				break;
			case 'ttck-export':
				$this->render_export_tab();
				break;
			case 'ttck-vietinbank':
				$this->render_vietinbank_tab();
				break;
			case 'ttck-vietinbank-transactions':
				$this->render_vietinbank_transactions_tab();
				break;
			default:
				$this->render_settings_tab();
				break;
		}

		echo '</div>';

		do_action('ttck_admin_page_footer');
	}

	private function render_tabs($current)
	{
		$tabs = array(
			self::MENU_SLUG     => __('Cài đặt chung', 'thanh-toan-chuyen-khoan'),
			'ttck-banks'        => __('Tài khoản ngân hàng', 'thanh-toan-chuyen-khoan'),
			'ttck-transactions' => __('Giao dịch', 'thanh-toan-chuyen-khoan'),
		);

		// Tab chốt cấu hình chỉ hiện cho người được phép — xem export_capability()
		if ($this->can_export()) {
			$tabs['ttck-export']                  = __('Xuất cấu hình', 'thanh-toan-chuyen-khoan');
			$tabs['ttck-vietinbank']              = __('VietinBank API', 'thanh-toan-chuyen-khoan');
			$tabs['ttck-vietinbank-transactions'] = __('Quản lý giao dịch thanh toán', 'thanh-toan-chuyen-khoan');
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ($tabs as $slug => $label) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url(admin_url('admin.php?page=' . $slug)),
				$slug === $current ? 'nav-tab-active' : '',
				esc_html($label)
			);
		}
		echo '</h2>';
	}

	/* ------------------------------ Tab 1 ----------------------------- */

	private function render_settings_tab()
	{
		$settings     = $this->settings;
		$bank_transfer = $settings['bank_transfer'];
		$secure_token = (string) $bank_transfer['secure_token'];
		$is_https     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		?>
		<form method="post">
			<input type="hidden" name="action" value="ttck_save_settings">
			<input type="hidden" name="ttck_nonce" value="<?php echo esc_attr(wp_create_nonce('ttck_save_settings')); ?>">

			<h2><?php esc_html_e('Hoạt động', 'thanh-toan-chuyen-khoan'); ?></h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e('Mở/Đóng', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<label>
								<input name="settings[bank_transfer][enabled]" type="checkbox" value="yes" <?php checked('yes', $bank_transfer['enabled']); ?>>
								<?php esc_html_e('Bật nhận thanh toán chuyển khoản', 'thanh-toan-chuyen-khoan'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Nguồn ảnh QR', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<select name="settings[qr_engine]">
								<option value="vietqr" <?php selected('vietqr', $settings['qr_engine']); ?>>
									<?php esc_html_e('api.vietqr.io (mặc định)', 'thanh-toan-chuyen-khoan'); ?>
								</option>
								<option value="local" <?php selected('local', $settings['qr_engine']); ?>>
									<?php esc_html_e('Tự sinh trên server (không phụ thuộc dịch vụ ngoài)', 'thanh-toan-chuyen-khoan'); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e('Chọn "Tự sinh trên server" nếu muốn QR không phụ thuộc internet ra ngoài. Nội dung mã hoàn toàn giống chuẩn VietQR.', 'thanh-toan-chuyen-khoan'); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Hiệu lực yêu cầu thanh toán', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<input type="number" min="1" max="1440" name="settings[payment_expire_minutes]"
								value="<?php echo esc_attr($settings['payment_expire_minutes']); ?>"> <?php esc_html_e('phút', 'thanh-toan-chuyen-khoan'); ?>
							<p class="description"><?php esc_html_e('Quá thời gian này mà chưa có tiền về thì yêu cầu chuyển sang "hết hạn".', 'thanh-toan-chuyen-khoan'); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e('Nhận diện giao dịch', 'thanh-toan-chuyen-khoan'); ?></h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e('Tiền tố', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<!-- id="prefix": assets/js/js.js tự viết hoa và chặn ký tự số cho ô này -->
							<input name="settings[bank_transfer][transaction_prefix]" type="text" id="prefix"
								value="<?php echo esc_attr($bank_transfer['transaction_prefix']); ?>">
							<p class="description"><?php esc_html_e('Tối đa 15 ký tự, không dấu cách, không ký tự đặc biệt, không số. Nội dung chuyển khoản = tiền tố + mã yêu cầu.', 'thanh-toan-chuyen-khoan'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Nội dung trước tiền tố (không bắt buộc)', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<input type="text" name="settings[bank_transfer][extra_text]"
								value="<?php echo esc_attr($bank_transfer['extra_text']); ?>">
							<p class="description"><?php esc_html_e("Ví dụ: 'chuyen khoan BIZGPT123' — 'chuyen khoan' là phần thêm.", 'thanh-toan-chuyen-khoan'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Bỏ qua hoa/thường', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<label>
								<input name="settings[bank_transfer][case_insensitive]" type="checkbox" value="yes" <?php checked('yes', $bank_transfer['case_insensitive']); ?>>
								<?php esc_html_e('Khớp mã giao dịch không phân biệt chữ hoa/thường', 'thanh-toan-chuyen-khoan'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Chênh lệch chấp nhận (VND)', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<input name="settings[bank_transfer][acceptable_difference]" type="number" min="0"
								value="<?php echo esc_attr($bank_transfer['acceptable_difference']); ?>">
							<p class="description"><?php esc_html_e('Thiếu trong khoảng này vẫn coi là đã thanh toán đủ.', 'thanh-toan-chuyen-khoan'); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e('Kết nối app & thông báo', 'thanh-toan-chuyen-khoan'); ?></h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e('Tải app & quét mã QR để liên kết website', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<p>
								<a href="https://play.google.com/store/apps/details?id=com.hoangweb.checkpay" target="_blank" rel="noopener">
									<img src="<?php echo esc_url(TTCK_URL . 'assets/playstore-btn.png'); ?>" alt="Google Play"/>
								</a>
							</p>
							<div id="ttckqrcode"></div>
							<?php if (!$is_https && !TTCK_TEST) : ?>
								<p class="ttck-error-tip" style="color:#b32d2e;">
									<?php esc_html_e('Không sinh được mã QR liên kết vì website chưa có SSL.', 'thanh-toan-chuyen-khoan'); ?>
								</p>
							<?php elseif ($secure_token === '' || $bank_transfer['transaction_prefix'] === '') : ?>
								<p class="ttck-error-tip" style="color:#b32d2e;">
									<?php esc_html_e('Hãy lưu cài đặt (có tiền tố) để hiện mã QR liên kết.', 'thanh-toan-chuyen-khoan'); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Secure Token', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<code><?php echo $secure_token !== '' ? esc_html($secure_token) : '&mdash;'; ?></code>
							<p class="description"><?php esc_html_e('App điện thoại gửi token này ở header Secure-Token khi báo biến động số dư.', 'thanh-toan-chuyen-khoan'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Telegram Bot Token', 'thanh-toan-chuyen-khoan'); ?></th>
						<td><input type="text" class="regular-text" name="settings[telegram_token]" value="<?php echo esc_attr($settings['telegram_token']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Telegram Group ID', 'thanh-toan-chuyen-khoan'); ?></th>
						<td><input type="text" class="regular-text" name="settings[telegram_chatid]" value="<?php echo esc_attr($settings['telegram_chatid']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Telegram Webhook Secret', 'thanh-toan-chuyen-khoan'); ?></th>
						<td><?php $this->render_secret_field('telegram_webhook_secret', $settings['telegram_webhook_secret'], __('Dùng chuỗi này khi setWebhook với tham số token=... (không dùng dấu :).', 'thanh-toan-chuyen-khoan')); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('TGS HMAC Secret (App Signing)', 'thanh-toan-chuyen-khoan'); ?></th>
						<td><?php $this->render_secret_field('tgs_hmac_secret', $settings['tgs_hmac_secret'], __('Khoá xác thực chữ ký HMAC-SHA256 từ app Android. Để trống = bỏ qua kiểm tra chữ ký.', 'thanh-toan-chuyen-khoan')); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Webhook chuyển tiếp', 'thanh-toan-chuyen-khoan'); ?></th>
						<td>
							<input type="url" class="regular-text" name="settings[webhook]" value="<?php echo esc_attr($settings['webhook']); ?>">
							<p class="description"><?php esc_html_e('Nếu điền, mọi biến động số dư sẽ được POST tiếp sang URL này.', 'thanh-toan-chuyen-khoan'); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button(__('Lưu thay đổi', 'thanh-toan-chuyen-khoan')); ?>
		</form>

		<form method="post" onsubmit="return confirm('<?php echo esc_js(__('Tạo Secure Token mới? App trên điện thoại sẽ phải quét lại mã liên kết.', 'thanh-toan-chuyen-khoan')); ?>');">
			<input type="hidden" name="action" value="ttck_reset_token">
			<input type="hidden" name="ttck_nonce" value="<?php echo esc_attr(wp_create_nonce('ttck_reset_token')); ?>">
			<?php submit_button(__('Tạo Secure Token mới', 'thanh-toan-chuyen-khoan'), 'delete', 'submit', true); ?>
		</form>

		<script type="text/javascript">
		jQuery(function ($) {
			<?php if (($is_https || TTCK_TEST) && $secure_token !== '' && $bank_transfer['transaction_prefix'] !== '') : ?>
			new QRCode($('#ttckqrcode')[0], {
				text: <?php echo wp_json_encode(base64_encode(wp_json_encode(array(
						'pf'  => $bank_transfer['transaction_prefix'],
						'tk'  => $secure_token,
						'url' => admin_url('admin-ajax.php') . '?action=paid_order_ttck',
					)))); ?>,
				width: 256,
				height: 256,
				colorDark: "#000000",
				colorLight: "#ffffff",
				logo: <?php echo wp_json_encode(TTCK_URL . 'assets/logo.png'); ?>
			});
			<?php endif; ?>

			// Giữ nguyên secret cũ nếu admin không tích "Thay đổi".
			$('form').on('submit', function () {
				$('.ttck-secret-input').each(function () {
					var $input = $(this);
					if (!$('#' + $input.data('toggle')).is(':checked')) {
						$input.val('***UNCHANGED***');
					}
				});
			});
		});
		</script>
		<?php
	}

	private function render_secret_field($field, $value, $description)
	{
		$has_value = ('' !== trim((string) $value));
		$toggle_id = $field . '_change';
		?>
		<?php if ($has_value) : ?>
			<p style="margin:5px 0;font-weight:bold;">● <?php esc_html_e('Đã cấu hình:', 'thanh-toan-chuyen-khoan'); ?> <span style="font-family:monospace;">***</span></p>
			<label>
				<input type="checkbox" id="<?php echo esc_attr($toggle_id); ?>"
					onclick="document.getElementById('<?php echo esc_js($field); ?>_input').style.display = this.checked ? 'block' : 'none';">
				<?php esc_html_e('Thay đổi giá trị này', 'thanh-toan-chuyen-khoan'); ?>
			</label>
			<input type="text" class="regular-text ttck-secret-input" id="<?php echo esc_attr($field); ?>_input"
				data-toggle="<?php echo esc_attr($toggle_id); ?>"
				name="settings[<?php echo esc_attr($field); ?>]"
				placeholder="<?php esc_attr_e('Nhập giá trị mới...', 'thanh-toan-chuyen-khoan'); ?>"
				style="display:none;margin-top:5px;">
		<?php else : ?>
			<input type="text" class="regular-text" name="settings[<?php echo esc_attr($field); ?>]" value="">
		<?php endif; ?>
		<p class="description"><?php echo esc_html($description); ?></p>
		<?php
	}

	/* ------------------------------ Tab 2 ----------------------------- */

	private function render_banks_tab()
	{
		$settings = $this->settings;
		$accounts = is_array($settings['bank_transfer_accounts']) ? $settings['bank_transfer_accounts'] : array();
		$meta     = is_array($settings['bank_meta']) ? $settings['bank_meta'] : array();
		$catalog  = TTCK_Banks::all();

		// Sắp xếp theo thứ tự admin đã đặt.
		$bank_ids = array_keys($accounts);
		usort($bank_ids, function ($a, $b) use ($meta) {
			return ((int) ($meta[$a]['sort'] ?? 0)) <=> ((int) ($meta[$b]['sort'] ?? 0));
		});
		?>
		<p class="description">
			<?php esc_html_e('Mỗi ngân hàng một dòng. Chỉ những dòng được tích "Bật" mới hiện ra cho POS chọn.', 'thanh-toan-chuyen-khoan'); ?>
		</p>

		<form method="post">
			<input type="hidden" name="action" value="ttck_save_banks">
			<input type="hidden" name="ttck_nonce" value="<?php echo esc_attr(wp_create_nonce('ttck_save_banks')); ?>">

			<table class="widefat striped" id="ttck-banks-table">
				<thead>
					<tr>
						<th style="width:60px;"><?php esc_html_e('Bật', 'thanh-toan-chuyen-khoan'); ?></th>
						<th style="width:220px;"><?php esc_html_e('Ngân hàng', 'thanh-toan-chuyen-khoan'); ?></th>
						<th style="width:110px;"><?php esc_html_e('BIN', 'thanh-toan-chuyen-khoan'); ?></th>
						<th><?php esc_html_e('Số tài khoản', 'thanh-toan-chuyen-khoan'); ?></th>
						<th><?php esc_html_e('Tên tài khoản', 'thanh-toan-chuyen-khoan'); ?></th>
						<th><?php esc_html_e('Tiêu đề hiển thị', 'thanh-toan-chuyen-khoan'); ?></th>
						<th style="width:60px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$index = 0;
					foreach ($bank_ids as $bank_id) {
						$rows = $accounts[$bank_id];
						if (!is_array($rows) || empty($rows[0])) {
							continue;
						}
						$this->render_bank_row($index++, $bank_id, $rows[0], $meta[$bank_id] ?? array(), $catalog);
					}
					?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="ttck-add-bank">+ <?php esc_html_e('Thêm ngân hàng', 'thanh-toan-chuyen-khoan'); ?></button>
			</p>

			<?php submit_button(__('Lưu tài khoản', 'thanh-toan-chuyen-khoan')); ?>
		</form>

		<script type="text/javascript">
		jQuery(function ($) {
			var index = <?php echo (int) $index; ?>;
			var template = <?php echo wp_json_encode($this->bank_row_template($catalog)); ?>;

			$('#ttck-add-bank').on('click', function () {
				$('#ttck-banks-table tbody').append(template.replace(/__INDEX__/g, index++));
			});

			$('#ttck-banks-table').on('click', '.ttck-remove-bank', function () {
				$(this).closest('tr').remove();
			});

			// Tự điền BIN khi đổi ngân hàng.
			$('#ttck-banks-table').on('change', '.ttck-bank-select', function () {
				var $row = $(this).closest('tr');
				$row.find('.ttck-bank-bin').text($(this).find(':selected').data('bin') || '—');
			});
		});
		</script>
		<?php
	}

	private function render_bank_row($index, $bank_id, $account, $bank_meta, $catalog)
	{
		$bank = TTCK_Banks::get($bank_id);
		?>
		<tr>
			<td>
				<input type="checkbox" name="bank[<?php echo (int) $index; ?>][enabled]" value="1"
					<?php checked('yes', $bank_meta['enabled'] ?? 'no'); ?>>
			</td>
			<td>
				<?php $icon = TTCK_Banks::icon_url($bank_id); ?>
				<?php if ($icon) : ?>
					<img src="<?php echo esc_url($icon); ?>" alt="" style="height:20px;vertical-align:middle;margin-right:6px;">
				<?php endif; ?>
				<select class="ttck-bank-select" name="bank[<?php echo (int) $index; ?>][bank_id]">
					<?php foreach ($catalog as $id => $info) : ?>
						<option value="<?php echo esc_attr($id); ?>" data-bin="<?php echo esc_attr($info['bin']); ?>" <?php selected($id, $bank_id); ?>>
							<?php echo esc_html($info['label']); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><code class="ttck-bank-bin"><?php echo esc_html($bank['bin'] !== '' ? $bank['bin'] : '—'); ?></code></td>
			<td>
				<input type="text" class="regular-text" name="bank[<?php echo (int) $index; ?>][account_number]"
					value="<?php echo esc_attr($account['account_number'] ?? ''); ?>" required>
			</td>
			<td>
				<input type="text" class="regular-text" name="bank[<?php echo (int) $index; ?>][account_name]"
					value="<?php echo esc_attr($account['account_name'] ?? ''); ?>">
			</td>
			<td>
				<input type="text" class="regular-text" name="bank[<?php echo (int) $index; ?>][title]"
					value="<?php echo esc_attr($bank_meta['title'] ?? ''); ?>"
					placeholder="<?php echo esc_attr(sprintf(__('Quét Mã %s', 'thanh-toan-chuyen-khoan'), $bank['label'])); ?>">
			</td>
			<td><button type="button" class="button-link delete ttck-remove-bank"><?php esc_html_e('Xoá', 'thanh-toan-chuyen-khoan'); ?></button></td>
		</tr>
		<?php
	}

	private function bank_row_template($catalog)
	{
		ob_start();
		$this->render_bank_row('__INDEX__', '', array(), array(), $catalog);
		// render_bank_row ép kiểu int cho index nên phải trả lại placeholder.
		return str_replace(array('name="bank[0]'), array('name="bank[__INDEX__]'), ob_get_clean());
	}

	/* ------------------------------ Tab 3 ----------------------------- */

	private function render_transactions_tab()
	{
		$status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$paged  = max(1, (int) ($_GET['paged'] ?? 1));

		$result   = TTCK_Payments::query(array(
			'status'   => $status,
			'search'   => $search,
			'page'     => $paged,
			'per_page' => 30,
		));
		$per_page = 30;

		$labels = array(
			TTCK_Payments::STATUS_PENDING   => __('Chờ thanh toán', 'thanh-toan-chuyen-khoan'),
			TTCK_Payments::STATUS_PAID      => __('Đã thanh toán', 'thanh-toan-chuyen-khoan'),
			TTCK_Payments::STATUS_UNDERPAID => __('Thiếu tiền', 'thanh-toan-chuyen-khoan'),
			TTCK_Payments::STATUS_CANCELLED => __('Đã huỷ', 'thanh-toan-chuyen-khoan'),
			TTCK_Payments::STATUS_EXPIRED   => __('Hết hạn', 'thanh-toan-chuyen-khoan'),
		);
		?>
		<form method="get" style="margin:15px 0;">
			<input type="hidden" name="page" value="ttck-transactions">
			<select name="status">
				<option value=""><?php esc_html_e('Mọi trạng thái', 'thanh-toan-chuyen-khoan'); ?></option>
				<?php foreach ($labels as $key => $label) : ?>
					<option value="<?php echo esc_attr($key); ?>" <?php selected($key, $status); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr($search); ?>"
				placeholder="<?php esc_attr_e('Mã giao dịch / nội dung / số TK', 'thanh-toan-chuyen-khoan'); ?>">
			<?php submit_button(__('Lọc', 'thanh-toan-chuyen-khoan'), 'secondary', '', false); ?>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Mã', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Ngân hàng', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Số tiền', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Thực nhận', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Trạng thái', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Nguồn', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Tạo lúc', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Tiền về lúc', 'thanh-toan-chuyen-khoan'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($result['rows'])) : ?>
					<tr><td colspan="8"><?php esc_html_e('Chưa có giao dịch nào.', 'thanh-toan-chuyen-khoan'); ?></td></tr>
				<?php else : ?>
					<?php foreach ($result['rows'] as $row) : ?>
						<tr>
							<td><code><?php echo esc_html($row['ref_code']); ?></code></td>
							<td><?php echo esc_html(TTCK_Banks::label($row['bank_id'])); ?><br><small><?php echo esc_html($row['account_number']); ?></small></td>
							<td><?php echo esc_html(number_format($row['amount'], 0, ',', '.')); ?></td>
							<td><?php echo esc_html(number_format($row['paid_amount'], 0, ',', '.')); ?></td>
							<td><?php echo esc_html($labels[$row['status']] ?? $row['status']); ?></td>
							<td><?php echo esc_html(trim($row['source'] . ' ' . $row['source_ref'])); ?></td>
							<td><?php echo esc_html($row['created_at']); ?></td>
							<td><?php echo esc_html($row['paid_at'] ?: '—'); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php
		$total_pages = (int) ceil($result['total'] / $per_page);
		if ($total_pages > 1) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(paginate_links(array(
				'base'    => add_query_arg('paged', '%#%'),
				'format'  => '',
				'total'   => $total_pages,
				'current' => $paged,
			)));
			echo '</div></div>';
		}
	}

	/* ------------------------------ Tab 4 ----------------------------- */

	/**
	 * Màn CHỐT CẤU HÌNH.
	 *
	 * Ba việc trên cùng một màn, cố ý để cạnh nhau:
	 *   1. Xem file đang chốt là bản nào, ai chốt, lúc nào.
	 *   2. Chốt lại (xuất file) khi cấu hình đã sửa xong.
	 *   3. Đối chiếu DB với file — chỗ này mới là chỗ phát hiện bất thường.
	 */
	private function render_export_tab()
	{
		/*
		 * Chặn lại ở đây chứ không chỉ giấu tab: bảng đối chiếu bên dưới hiện
		 * tài khoản của MỌI shop, mà gõ thẳng ?page=ttck-export thì bỏ qua menu.
		 */
		if (!$this->can_export()) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__('Chỉ quản trị viên toàn mạng mới xem được màn chốt cấu hình.', 'thanh-toan-chuyen-khoan')
				. '</p></div>';
			return;
		}

		$status = TTCK_Account_File::status();
		$can    = true;
		?>
		<p class="description">
			<?php esc_html_e('Mã QR cấp tài khoản theo FILE dưới đây, không theo cơ sở dữ liệu. Sửa cấu hình xong phải chốt lại thì mới có hiệu lực.', 'thanh-toan-chuyen-khoan'); ?>
		</p>

		<h2><?php esc_html_e('File đang chốt', 'thanh-toan-chuyen-khoan'); ?></h2>
		<table class="widefat striped" style="max-width:900px;">
			<tbody>
				<tr>
					<td style="width:220px;"><strong><?php esc_html_e('Đường dẫn', 'thanh-toan-chuyen-khoan'); ?></strong></td>
					<td><code><?php echo esc_html($status['path']); ?></code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e('Tình trạng', 'thanh-toan-chuyen-khoan'); ?></strong></td>
					<td>
						<?php if (!$status['exists']) : ?>
							<span style="color:#b32d2e;font-weight:600;"><?php esc_html_e('CHƯA CÓ FILE — mọi shop đều không quét QR được', 'thanh-toan-chuyen-khoan'); ?></span>
						<?php elseif (!$status['valid']) : ?>
							<span style="color:#b32d2e;font-weight:600;"><?php esc_html_e('FILE HỎNG — không đọc được JSON', 'thanh-toan-chuyen-khoan'); ?></span>
						<?php else : ?>
							<span style="color:#008a20;font-weight:600;"><?php esc_html_e('Đang dùng', 'thanh-toan-chuyen-khoan'); ?></span>
							&middot; <?php echo esc_html(sprintf(__('%1$d shop, %2$d tài khoản', 'thanh-toan-chuyen-khoan'), $status['shop_count'], $status['account_count'])); ?>
							&middot; <?php echo esc_html(size_format($status['size'])); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ($status['valid']) : ?>
					<tr>
						<td><strong><?php esc_html_e('Chốt lúc', 'thanh-toan-chuyen-khoan'); ?></strong></td>
						<td>
							<?php echo esc_html($status['generated_at']); ?>
							<?php if ($status['generated_by'] !== '') : ?>
								&middot; <?php echo esc_html($status['generated_by']); ?>
							<?php endif; ?>
							<span class="description">(<?php esc_html_e('sửa file lần cuối', 'thanh-toan-chuyen-khoan'); ?>: <?php echo esc_html($status['modified_at']); ?>)</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e('Tổng kiểm', 'thanh-toan-chuyen-khoan'); ?></strong></td>
						<td>
							<code><?php echo esc_html(substr($status['checksum'], 0, 16)); ?>…</code>
							<?php if ($status['checksum_ok']) : ?>
								<span style="color:#008a20;">✓ <?php esc_html_e('khớp', 'thanh-toan-chuyen-khoan'); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;font-weight:600;">✗ <?php esc_html_e('KHÔNG KHỚP — nội dung file đã bị sửa tay', 'thanh-toan-chuyen-khoan'); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<td><strong><?php esc_html_e('Khoá ghi', 'thanh-toan-chuyen-khoan'); ?></strong></td>
					<td>
						<?php if ($status['writable']) : ?>
							<span style="color:#996800;font-weight:600;"><?php esc_html_e('CHƯA KHOÁ — file đang ghi được', 'thanh-toan-chuyen-khoan'); ?></span>
							<p class="description" style="margin:4px 0 0;">
								<?php esc_html_e('Chạy thật thì nên khoá lại ở server, để mã nguồn cũng không ghi đè được.', 'thanh-toan-chuyen-khoan'); ?>
							</p>
						<?php else : ?>
							<span style="color:#008a20;font-weight:600;"><?php esc_html_e('Đã khoá — không ghi đè được', 'thanh-toan-chuyen-khoan'); ?></span>
							<p class="description" style="margin:4px 0 0;">
								<?php esc_html_e('Muốn chốt bản mới thì mở khoá ở server, bấm "Chốt cấu hình", rồi khoá lại.', 'thanh-toan-chuyen-khoan'); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php $this->render_exposure_check(); ?>

		<h2><?php esc_html_e('Chốt cấu hình', 'thanh-toan-chuyen-khoan'); ?></h2>
		<?php if (!$can) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e('Chỉ quản trị viên toàn mạng mới chốt được cấu hình. Bạn vẫn xem và đối chiếu được ở dưới.', 'thanh-toan-chuyen-khoan'); ?>
			</p></div>
		<?php else : ?>
			<form method="post" style="margin-bottom:20px;">
				<input type="hidden" name="ttck_nonce" value="<?php echo esc_attr(wp_create_nonce('ttck_export_accounts')); ?>">
				<button type="submit" name="action" value="ttck_export_accounts" class="button button-primary">
					<?php esc_html_e('Chốt cấu hình (ghi đè file)', 'thanh-toan-chuyen-khoan'); ?>
				</button>
				<button type="submit" name="action" value="ttck_download_accounts" class="button">
					<?php esc_html_e('Tải JSON về máy', 'thanh-toan-chuyen-khoan'); ?>
				</button>
				<p class="description">
					<?php esc_html_e('"Chốt" đọc cấu hình của toàn bộ site trong mạng rồi ghi đè file. "Tải về" chỉ xem trước, không đụng vào file đang chạy.', 'thanh-toan-chuyen-khoan'); ?>
				</p>
			</form>
		<?php endif; ?>

		<?php $this->render_compare_table(); ?>
		<?php
	}

	/**
	 * File có tải được từ internet không.
	 *
	 * Thư mục có sẵn .htaccess, nhưng nginx không đọc .htaccess — nên phải
	 * thử THẬT bằng một request vào chính URL đó thay vì tin là đã chặn.
	 * Nhớ kết quả một giờ, kiểm tra lại thì bấm nút.
	 */
	private function render_exposure_check()
	{
		$key   = 'ttck_exposure_check';
		$force = isset($_GET['ttck_recheck']);
		$check = $force ? false : get_transient($key);

		if ($check === false) {
			$check = TTCK_Account_File::check_public_exposure();
			set_transient($key, $check, HOUR_IN_SECONDS);
		}

		$recheck = esc_url(add_query_arg('ttck_recheck', time()));

		echo '<p style="margin-top:12px;">';

		if (empty($check['checked'])) {
			echo '<span class="description">' . esc_html__('Chưa kiểm tra được khả năng lộ file:', 'thanh-toan-chuyen-khoan')
				. ' ' . esc_html($check['reason'] ?? '') . '</span>';
		} elseif (!empty($check['leaking'])) {
			echo '<strong style="color:#b32d2e;">'
				. esc_html__('CẢNH BÁO: ai cũng tải được file này từ internet.', 'thanh-toan-chuyen-khoan')
				. '</strong> <code>' . esc_html($check['url']) . '</code> '
				. esc_html__('trả về 200. Phải chặn trong cấu hình web server — xem docs/file-tai-khoan-json.md.', 'thanh-toan-chuyen-khoan');
		} else {
			echo '<span style="color:#008a20;font-weight:600;">'
				. esc_html__('Không tải được từ internet', 'thanh-toan-chuyen-khoan') . '</span> '
				. '<span class="description">(' . esc_html__('mã trả về', 'thanh-toan-chuyen-khoan') . ' '
				. esc_html((string) ($check['code'] ?? '?')) . ')</span>';
		}

		echo ' <a href="' . $recheck . '">' . esc_html__('Kiểm tra lại', 'thanh-toan-chuyen-khoan') . '</a>';
		echo '</p>';
	}

	/**
	 * Bảng đối chiếu DB ↔ file.
	 *
	 * Đây mới là thứ trả lời câu "có ai đổi tài khoản không". File là thứ
	 * đang cấp tài khoản nên DB lệch file KHÔNG mất tiền — nhưng nó nói cho
	 * biết có người vừa động vào cấu hình mà chưa được chốt.
	 */
	private function render_compare_table()
	{
		$compare = TTCK_Account_File::compare();
		$rows    = $compare['rows'];
		$sum     = $compare['summary'];
		$dups    = $compare['duplicates'];

		$only_issues = !isset($_GET['ttck_all']);
		?>
		<h2><?php esc_html_e('Đối chiếu cơ sở dữ liệu với file', 'thanh-toan-chuyen-khoan'); ?></h2>

		<p>
			<?php echo esc_html(sprintf(__('%d site', 'thanh-toan-chuyen-khoan'), $sum['total'])); ?>
			&middot; <span style="color:#008a20;"><?php echo esc_html(sprintf(__('%d khớp', 'thanh-toan-chuyen-khoan'), $sum['ok'])); ?></span>
			&middot; <span style="color:<?php echo $sum['diff'] > 0 ? '#b32d2e' : '#666'; ?>;font-weight:<?php echo $sum['diff'] > 0 ? '700' : '400'; ?>;">
				<?php echo esc_html(sprintf(__('%d LỆCH', 'thanh-toan-chuyen-khoan'), $sum['diff'])); ?>
			</span>
			&middot; <span style="color:<?php echo $sum['missing'] > 0 ? '#996800' : '#666'; ?>;">
				<?php echo esc_html(sprintf(__('%d chưa chốt', 'thanh-toan-chuyen-khoan'), $sum['missing'])); ?>
			</span>
			&middot; <span class="description"><?php echo esc_html(sprintf(__('%d chưa cấu hình', 'thanh-toan-chuyen-khoan'), $sum['empty'])); ?></span>
			<?php if ($sum['orphan'] > 0) : ?>
				&middot; <span style="color:#996800;"><?php echo esc_html(sprintf(__('%d thừa trong file', 'thanh-toan-chuyen-khoan'), $sum['orphan'])); ?></span>
			<?php endif; ?>
		</p>

		<?php if (!empty($dups)) : ?>
			<div class="notice notice-error inline">
				<p><strong><?php echo esc_html(sprintf(__('%d số tài khoản đang dùng chung cho nhiều shop', 'thanh-toan-chuyen-khoan'), count($dups))); ?></strong></p>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e('Hầu như luôn là do nhân bản site để tạo shop mới: cấu hình đi theo bản sao, và tiền của shop mới chảy về tài khoản shop cũ.', 'thanh-toan-chuyen-khoan'); ?>
				</p>
				<ul style="margin:8px 0 12px 20px;list-style:disc;">
					<?php foreach ($dups as $key => $shops) : ?>
						<li>
							<code><?php echo esc_html($key); ?></code> —
							<?php
							$names = array();
							foreach ($shops as $shop) {
								$names[] = ($shop['site_code'] !== '' ? $shop['site_code'] . ' ' : '')
									. $shop['name'] . ' (blog ' . $shop['blog_id'] . ')';
							}
							echo esc_html(implode(' · ', $names));
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<p>
			<?php if ($only_issues) : ?>
				<a href="<?php echo esc_url(add_query_arg('ttck_all', 1)); ?>"><?php esc_html_e('Hiện tất cả site', 'thanh-toan-chuyen-khoan'); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url(remove_query_arg('ttck_all')); ?>"><?php esc_html_e('Chỉ hiện site có vấn đề', 'thanh-toan-chuyen-khoan'); ?></a>
			<?php endif; ?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:70px;"><?php esc_html_e('Mã shop', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Site', 'thanh-toan-chuyen-khoan'); ?></th>
					<th style="width:130px;"><?php esc_html_e('Trạng thái', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Đang cấp (file)', 'thanh-toan-chuyen-khoan'); ?></th>
					<th><?php esc_html_e('Trong DB', 'thanh-toan-chuyen-khoan'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$shown = 0;
				foreach ($rows as $row) :
					if ($only_issues && in_array($row['state'], array('ok', 'empty'), true)) {
						continue;
					}
					$shown++;
					?>
					<tr>
						<td><strong><?php echo esc_html($row['site_code'] !== '' ? $row['site_code'] : '—'); ?></strong></td>
						<td>
							<?php echo esc_html($row['name']); ?>
							<br><span class="description"><?php echo esc_html($row['domain']); ?> · blog <?php echo esc_html((string) $row['blog_id']); ?></span>
						</td>
						<td><?php echo wp_kses_post($this->state_badge($row['state'])); ?></td>
						<td><?php echo wp_kses_post($this->accounts_cell($row['file'], $row['db'])); ?></td>
						<td><?php echo wp_kses_post($this->accounts_cell($row['db'], $row['file'])); ?></td>
					</tr>
				<?php endforeach; ?>

				<?php if ($shown === 0) : ?>
					<tr><td colspan="5" style="padding:16px;">
						<?php esc_html_e('Không có site nào lệch. Cấu hình đang chạy khớp hoàn toàn với file đã chốt.', 'thanh-toan-chuyen-khoan'); ?>
					</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/** Nhãn màu cho một trạng thái đối chiếu */
	private function state_badge($state)
	{
		$map = array(
			'ok'      => array('#008a20', __('Khớp', 'thanh-toan-chuyen-khoan')),
			'diff'    => array('#b32d2e', __('LỆCH', 'thanh-toan-chuyen-khoan')),
			'missing' => array('#996800', __('Chưa chốt', 'thanh-toan-chuyen-khoan')),
			'empty'   => array('#666666', __('Chưa cấu hình', 'thanh-toan-chuyen-khoan')),
			'orphan'  => array('#996800', __('Thừa trong file', 'thanh-toan-chuyen-khoan')),
		);

		list($color, $label) = $map[$state] ?? array('#666666', $state);

		return '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html($label) . '</span>';
	}

	/**
	 * Một ô tài khoản, tô đỏ những dòng khác với bên kia.
	 *
	 * Đưa hai cột cạnh nhau mà không chỉ ra khác chỗ nào thì người đọc vẫn
	 * phải dò từng chữ số — đúng lúc đang vội mới là lúc dò sót.
	 */
	private function accounts_cell(array $accounts, array $other)
	{
		if (empty($accounts)) {
			return '<span class="description">—</span>';
		}

		$out = array();

		foreach ($accounts as $bank_id => $acc) {
			$number = trim((string) ($acc['account_number'] ?? ''));
			$name   = trim((string) ($acc['account_name'] ?? ''));

			$peer       = $other[$bank_id] ?? null;
			$peer_num   = $peer ? trim((string) ($peer['account_number'] ?? '')) : null;
			$is_diff    = ($peer_num === null || $peer_num !== $number);
			$style      = $is_diff ? 'color:#b32d2e;font-weight:600;' : '';
			$off_label  = empty($acc['enabled']) ? ' <span class="description">(tắt)</span>' : '';

			$out[] = '<div style="' . esc_attr($style) . '">'
				. esc_html(strtoupper((string) $bank_id)) . ' · <code>' . esc_html($number) . '</code>'
				. ($name !== '' ? ' · ' . esc_html($name) : '')
				. $off_label
				. '</div>';
		}

		return implode('', $out);
	}
}
