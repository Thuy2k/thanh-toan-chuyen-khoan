<?php
/**
 * API công khai của plugin thanh toán quét mã QR.
 *
 * Đây là điểm tích hợp duy nhất cho các plugin khác (tgs_pos...). Không plugin
 * nào cần biết tới WooCommerce hay cấu trúc bảng bên trong.
 *
 * Ví dụ dùng trong POS:
 *
 *     $payment = TTCK_API::create_payment([
 *         'bank_id'    => 'vietinbank',   // hoặc 'ttck_up_vietinbank'
 *         'amount'     => 430000,
 *         'source'     => 'pos',
 *         'source_ref' => $sale_code,
 *         'payload'    => $sale_args,     // dữ liệu tự do, trả lại nguyên vẹn khi tiền về
 *     ]);
 *     echo $payment['qr_url'];
 *
 *     $status = TTCK_API::get_status($payment['id']);
 */

if (!defined('ABSPATH')) {
	exit;
}

class TTCK_API
{
	/**
	 * Plugin đã bật nhận chuyển khoản chưa.
	 */
	public static function is_enabled()
	{
		return 'yes' === TTCKPayment::get_setting(array('bank_transfer', 'enabled'), 'no');
	}

	/**
	 * Bỏ tiền tố 'ttck_up_' để tương thích với cách gọi cũ theo gateway id.
	 */
	public static function normalize_bank_id($bank_id)
	{
		$bank_id = strtolower(trim((string) $bank_id));

		if (strpos($bank_id, 'ttck_up_') === 0) {
			$bank_id = substr($bank_id, strlen('ttck_up_'));
		}

		return $bank_id;
	}

	/**
	 * Danh sách tài khoản nhận tiền đang bật.
	 *
	 * ── LẤY TỪ FILE JSON, KHÔNG LẤY TỪ DB ───────────────────────────────
	 *
	 * Đây là cửa DUY NHẤT mà tài khoản nhận tiền đi qua: create_payment()
	 * gọi get_bank(), get_bank() gọi hàm này, và tgs_pos cũng chỉ gọi qua
	 * đây. Nên chốt ở đúng chỗ này là chốt được cả đường tiền.
	 *
	 * Trước đây đọc option `ttck` của từng site — ai sửa được DB là đổi
	 * được tài khoản khách quét. Nay đọc file JSON đã chốt, file thì khoá
	 * cứng ở tầng hệ điều hành. Sửa DB vẫn sửa được, nhưng không đổi được
	 * đồng nào chảy đi đâu, và màn "Xuất cấu hình" chỉ mặt ngay chỗ lệch.
	 *
	 * Không có file thì trả về RỖNG chứ không rơi về DB: rơi về DB là mở
	 * lại đúng cái cửa vừa đóng. Thà không quét được QR còn hơn quét vào
	 * tài khoản người khác.
	 *
	 * @return array Mỗi phần tử: id, bank_id, title, name, icon, account_number,
	 *               account_name, bin, supports_qr.
	 */
	public static function get_banks($only_enabled = true)
	{
		$accounts = TTCK_Account_File::accounts_for_blog();

		if (empty($accounts)) {
			self::log_missing_accounts();
			return array();
		}

		$out = array();

		foreach ($accounts as $bank_id => $account) {
			$bank_id = strtolower((string) $bank_id);
			if (!is_array($account) || trim((string) ($account['account_number'] ?? '')) === '') {
				continue;
			}

			$enabled = !empty($account['enabled']);

			if ($only_enabled && !$enabled) {
				continue;
			}

			$bank = TTCK_Banks::get($bank_id);
			if (!$bank) {
				continue;
			}

			$title = isset($account['title']) && $account['title'] !== ''
				? $account['title']
				: sprintf(__('Quét Mã %s', 'thanh-toan-chuyen-khoan'), $bank['label']);

			$out[] = array(
				'id'             => 'ttck_up_' . $bank_id,
				'bank_id'        => $bank_id,
				'title'          => $title,
				'name'           => $bank['label'],
				'icon'           => TTCK_Banks::icon_url($bank_id),
				'bin'            => $bank['bin'],
				'account_number' => trim((string) $account['account_number']),
				'account_name'   => trim((string) ($account['account_name'] ?? '')),
				'enabled'        => $enabled,
				'sort'           => (int) ($account['sort'] ?? 0),
				'supports_qr'    => ($bank['bin'] !== '' || in_array($bank['qr'], array('momo', 'viettelpay'), true)),
			);
		}

		usort($out, function ($a, $b) {
			if ($a['sort'] === $b['sort']) {
				return strcmp($a['name'], $b['name']);
			}
			return $a['sort'] < $b['sort'] ? -1 : 1;
		});

		return $out;
	}

	public static function get_bank($bank_id)
	{
		$bank_id = self::normalize_bank_id($bank_id);

		foreach (self::get_banks(false) as $bank) {
			if ($bank['bank_id'] === $bank_id) {
				return $bank;
			}
		}

		return null;
	}

	/**
	 * Tên shop hiển thị trong nội dung chuyển khoản — đọc từ bank-accounts.json
	 * (trường `name` theo blog_id), rơi về tên site nếu file chưa có shop này.
	 */
	public static function shop_display_name($blog_id = 0)
	{
		$name = '';
		if (is_callable(array('TTCK_Account_File', 'shop_name_for_blog'))) {
			$name = (string) TTCK_Account_File::shop_name_for_blog($blog_id);
		}
		if ($name === '') {
			$name = (string) get_bloginfo('name');
		}

		return $name;
	}

	/**
	 * Nội dung chuyển khoản cho QR động của POS:
	 *
	 *     <mã phiếu bán chính> - <tên shop>
	 *
	 * VD: "18008B35A89 - TGS LYTHUONGKIET2". Luôn là mã phiếu bán CHÍNH (sale
	 * ledger đang mở), KHÔNG phải mã phiếu tách hàng khuyến mãi (bill Z) — bên
	 * gọi (`TGS_POS_Ajax_Order::save_order()`) truyền `$sale_code` của phiếu
	 * chính vào `bill_code`, dùng chung cho cả 3 luồng ra QR: bán một hình
	 * thức, "Đa hình thức thanh toán" (khi có dòng quét mã), và "Hoàn hàng kết
	 * hợp đổi trả" (QR của đơn bán mới) — cả ba đều đi qua đúng một chỗ tạo QR
	 * này nên không cần sửa riêng từng nơi.
	 *
	 * Không có bill_code (hiếm, ví dụ preview chưa gắn phiếu): rơi về chỉ tên
	 * shop, không bịa mã phiếu.
	 */
	public static function build_transfer_content($bill_code = '', $blog_id = 0)
	{
		$bill_code = trim((string) $bill_code);
		$shop_name = self::shop_display_name($blog_id);

		if ($bill_code === '') {
			return $shop_name;
		}

		return $bill_code . ' - ' . $shop_name;
	}

	/**
	 * Tạo yêu cầu thanh toán + mã QR động.
	 *
	 * @param array $args bank_id (bắt buộc), amount (bắt buộc), source,
	 *                    source_ref, payload, expires_in, bill_code, note.
	 * @return array|WP_Error Bản ghi kèm qr_url / qr_type / gateway_info.
	 */
	public static function create_payment(array $args)
	{
		if (!self::is_enabled()) {
			return new WP_Error('ttck_disabled', __('Chức năng thanh toán chuyển khoản đang tắt.', 'thanh-toan-chuyen-khoan'));
		}

		$bank = self::get_bank($args['bank_id'] ?? '');
		if (!$bank) {
			return new WP_Error('ttck_bank_not_found', __('Chưa cấu hình tài khoản cho ngân hàng này.', 'thanh-toan-chuyen-khoan'));
		}

		$args['bank_id']        = $bank['bank_id'];
		$args['bin']            = $bank['bin'];
		$args['account_number'] = $bank['account_number'];
		$args['account_name']   = $bank['account_name'];

		$payment = TTCK_Payments::create($args);
		if (is_wp_error($payment)) {
			return $payment;
		}

		return self::decorate($payment);
	}

	/**
	 * Lấy bản ghi thanh toán kèm thông tin QR.
	 */
	public static function get_payment($id)
	{
		$payment = TTCK_Payments::get($id);

		return $payment ? self::decorate($payment) : null;
	}

	public static function get_payment_by_ref($ref_code)
	{
		$payment = TTCK_Payments::get_by_ref($ref_code);

		return $payment ? self::decorate($payment) : null;
	}

	public static function find_payment_by_source($source, $source_ref, $status = '')
	{
		$payment = TTCK_Payments::find_by_source($source, $source_ref, $status);

		return $payment ? self::decorate($payment) : null;
	}

	/**
	 * Trạng thái thanh toán gọn cho vòng lặp poll của POS.
	 */
	public static function get_status($id)
	{
		$payment = TTCK_Payments::get($id);
		if (!$payment) {
			return null;
		}

		// Chuyển bản ghi treo đã quá hạn sang 'expired' ngay tại đây (POS poll
		// qua hàm này), không chờ cron ngày.
		TTCK_Payments::maybe_expire($payment);

		$expiry = self::expiry_info($payment);

		return array(
			'id'            => $payment['id'],
			'ref_code'      => $payment['ref_code'],
			'bill_code'     => $payment['bill_code'] ?? '',
			'status'        => $payment['status'],
			'is_paid'       => $payment['is_paid'],
			'is_expired'    => $expiry['is_expired'],
			'amount'        => $payment['amount'],
			'paid_amount'   => $payment['paid_amount'],
			'paid_at'       => $payment['paid_at'],
			'expires_at'    => $expiry['expires_at'],
			'expires_at_ts' => $expiry['expires_at_ts'],
			'server_now_ts' => $expiry['server_now_ts'],
			'seconds_left'  => $expiry['seconds_left'],
		);
	}

	/**
	 * Thông tin hạn dùng của một bản ghi, quy về CÙNG MỘT hệ quy chiếu thời
	 * gian (giờ local của site) để hiệu số giây luôn đúng dù máy bán lệch giờ.
	 */
	private static function expiry_info(array $payment)
	{
		$now_ts     = strtotime(current_time('mysql'));
		$expires_at = (string) ($payment['expires_at'] ?? '');
		$expires_ts = ($expires_at !== '' && $expires_at !== '0000-00-00 00:00:00')
			? strtotime($expires_at)
			: 0;

		$seconds_left = $expires_ts > 0 ? max(0, $expires_ts - $now_ts) : 0;
		$is_expired   = in_array($payment['status'] ?? '', array(TTCK_Payments::STATUS_EXPIRED), true)
			|| ($payment['status'] ?? '') === TTCK_Payments::STATUS_CANCELLED
			|| ($expires_ts > 0 && $seconds_left === 0 && ($payment['status'] ?? '') === TTCK_Payments::STATUS_PENDING);

		return array(
			'expires_at'    => $expires_at,
			'expires_at_ts' => $expires_ts,
			'server_now_ts' => $now_ts,
			'seconds_left'  => $seconds_left,
			'is_expired'    => (bool) $is_expired,
		);
	}

	/**
	 * Huỷ mã QR cũ và sinh mã mới cho cùng một đơn (nút "Tạo mã mới").
	 *
	 * Giữ nguyên ngân hàng / số tiền / source / source_ref / bill_code / payload
	 * của bản ghi cũ; cho phép override `amount` và `expires_in`.
	 *
	 * @return array|WP_Error Bản ghi mới đã decorate.
	 */
	public static function replace_payment($old_id, array $overrides = array())
	{
		$old = TTCK_Payments::get((int) $old_id);
		if (!$old) {
			return new WP_Error('ttck_not_found', __('Không tìm thấy yêu cầu thanh toán.', 'thanh-toan-chuyen-khoan'));
		}

		if ($old['status'] === TTCK_Payments::STATUS_PAID) {
			return new WP_Error('ttck_already_paid', __('Yêu cầu thanh toán đã được xử lý trước đó.', 'thanh-toan-chuyen-khoan'));
		}

		TTCK_Payments::cancel((int) $old_id, 'POS bấm "Tạo mã mới", huỷ mã QR cũ.');

		$args = array(
			'bank_id'    => 'ttck_up_' . $old['bank_id'],
			'amount'     => isset($overrides['amount']) ? $overrides['amount'] : $old['amount'],
			'source'     => $old['source'],
			'source_ref' => $old['source_ref'],
			'bill_code'  => $old['bill_code'] ?? '',
			'note'       => $old['note'],
		);

		if (isset($overrides['expires_in'])) {
			$args['expires_in'] = (int) $overrides['expires_in'];
		}

		$old_payload = self::get_payload((int) $old_id);
		if (is_array($old_payload)) {
			$args['payload'] = $old_payload;
		}

		return self::create_payment($args);
	}

	/**
	 * Nhân viên xác nhận thủ công đã nhận tiền.
	 */
	public static function mark_paid_manually($id, $note = '')
	{
		return TTCK_Payments::mark_paid_manually($id, $note);
	}

	public static function cancel_payment($id, $note = '')
	{
		return TTCK_Payments::cancel($id, $note);
	}

	/**
	 * Payload tự do mà bên gọi gửi kèm lúc tạo yêu cầu.
	 */
	public static function get_payload($id)
	{
		$payment = TTCK_Payments::get($id);
		if (!$payment || empty($payment['payload'])) {
			return null;
		}

		$data = json_decode((string) $payment['payload'], true);

		return is_array($data) ? $data : null;
	}

	public static function set_payload($id, $payload, $status = 'pending')
	{
		return TTCK_Payments::set_payload($id, $payload, $status);
	}

	public static function get_payload_status($id)
	{
		$payment = TTCK_Payments::get($id);

		return $payment ? (string) $payment['payload_status'] : '';
	}

	public static function mark_payload_committed($id, $committed_ref = '')
	{
		return TTCK_Payments::mark_payload_committed($id, $committed_ref);
	}

	/**
	 * Tham chiếu mà bên gọi ghi lại sau khi xử lý xong payload (POS: ID phiếu bán).
	 */
	public static function get_committed_ref($id)
	{
		$payment = TTCK_Payments::get($id);

		return $payment ? (string) $payment['committed_ref'] : '';
	}

	/**
	 * URL ảnh QR cho một bản ghi thanh toán.
	 */
	public static function qr_url(array $payment)
	{
		$bank   = TTCK_Banks::get($payment['bank_id']);
		$amount = (int) round((float) $payment['amount']);

		if ($payment['bin'] !== '' && is_numeric($payment['bin'])) {
			if ('vietqr' === TTCKPayment::get_setting('qr_engine', 'vietqr')) {
				return sprintf(
					'https://api.vietqr.io/%s/%s/%d/%s/qr_only.jpg',
					rawurlencode($payment['bin']),
					rawurlencode($payment['account_number']),
					$amount,
					rawurlencode($payment['content'])
				);
			}

			// Tự sinh tại chỗ: không phụ thuộc dịch vụ ngoài.
			return add_query_arg(
				array('pid' => $payment['id'], 'k' => $payment['qr_key']),
				get_rest_url(null, 'ttck/v1/qr')
			);
		}

		if (in_array($bank['qr'], array('momo', 'viettelpay'), true)) {
			return add_query_arg(
				array('pid' => $payment['id'], 'k' => $payment['qr_key']),
				get_rest_url(null, 'ttck/v1/qr')
			);
		}

		return '';
	}

	/**
	 * Bổ sung các trường tiện dụng cho bên gọi.
	 */
	private static function decorate(array $payment)
	{
		$bank = TTCK_Banks::get($payment['bank_id']);

		$payment['qr_url']  = self::qr_url($payment);
		$payment['qr_type'] = (is_numeric($payment['bin']) && $payment['bin'] !== '') ? 'vietqr' : $bank['qr'];
		$payment['bank_label'] = $bank['label'];
		$payment['bank_icon']  = TTCK_Banks::icon_url($payment['bank_id']);

		// Hạn dùng mã QR — POS dùng để chạy đồng hồ đếm ngược và khoá nút.
		$expiry = self::expiry_info($payment);
		$payment['expires_at']    = $expiry['expires_at'];
		$payment['expires_at_ts'] = $expiry['expires_at_ts'];
		$payment['server_now_ts'] = $expiry['server_now_ts'];
		$payment['seconds_left']  = $expiry['seconds_left'];
		$payment['is_expired']    = $expiry['is_expired'];

		// Giữ nguyên hình dạng mảng mà POS đang dùng để hiển thị.
		$payment['gateway_info'] = array(
			'bank_name'      => $bank['label'],
			'bank_id'        => $payment['bank_id'],
			'account_number' => $payment['account_number'],
			'account_name'   => $payment['account_name'],
			'amount'         => $payment['amount'],
			'content'        => $payment['content'],
			'icon'           => TTCK_Banks::icon_url($payment['bank_id']),
		);

		// payload là dữ liệu nội bộ của bên gọi, không trả kèm mặc định.
		unset($payment['payload']);

		return $payment;
	}

	/**
	 * Ghi log khi shop không có tài khoản nào để cấp.
	 *
	 * Nhìn từ màn bán hàng thì "shop chưa cấu hình" và "file cấu hình đang
	 * mất" giống hệt nhau, mà cách xử lý thì khác hẳn. Nên ghi rõ lý do ra
	 * log, kèm blog_id để tra đúng shop.
	 *
	 * Mỗi request chỉ ghi một lần: get_banks() bị gọi nhiều lần trong một
	 * lần dựng trang, ghi hết thì log ngập.
	 */
	private static function log_missing_accounts()
	{
		static $logged = array();

		$blog_id = get_current_blog_id();
		if (isset($logged[$blog_id])) {
			return;
		}
		$logged[$blog_id] = true;

		error_log(sprintf(
			'[TTCK] Shop blog_id=%d không có tài khoản nhận tiền: %s',
			$blog_id,
			TTCK_Account_File::why_empty($blog_id)
		));
	}
}
