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
		} elseif ('ttck_reset_token' === $action) {
			$this->reset_secure_token();
		} elseif ('ttck_export_accounts' === $action) {
			$this->export_accounts();
		} elseif ('ttck_download_accounts' === $action) {
			$this->download_accounts();
		}

		add_action('admin_menu', array($this, 'register_menu'));
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
			$tabs['ttck-export'] = __('Xuất cấu hình', 'thanh-toan-chuyen-khoan');
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
