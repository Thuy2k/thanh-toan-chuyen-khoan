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
		$this->saved_message();
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
}
