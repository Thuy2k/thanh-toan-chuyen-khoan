<?php
/**
 * Sổ yêu cầu thanh toán của TTCK.
 *
 * Trước đây plugin mượn đơn hàng WooCommerce làm nơi giữ số tiền + trạng thái
 * thanh toán. Bảng này thay thế hoàn toàn vai trò đó, nên plugin không còn cần
 * WooCommerce nữa.
 */

if (!defined('ABSPATH')) {
	exit;
}

class TTCK_Payments
{
	const DB_VERSION = '1.1.0';

	const STATUS_PENDING   = 'pending';
	const STATUS_PAID      = 'paid';
	const STATUS_UNDERPAID = 'underpaid';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_EXPIRED   = 'expired';

	public static function table()
	{
		global $wpdb;
		return $wpdb->prefix . 'ttck_payments';
	}

	/**
	 * Tạo/nâng cấp bảng. Gọi ở activation và ở init (multisite: site mới, site
	 * đã kích hoạt network-wide đều được tạo bảng khi truy cập lần đầu).
	 */
	public static function maybe_install()
	{
		if (get_option('ttck_db_version') === self::DB_VERSION) {
			return;
		}

		self::install();
		// autoload = true: `maybe_install()` chạy mỗi request nên tránh 1 query thừa.
		update_option('ttck_db_version', self::DB_VERSION, true);
	}

	public static function install()
	{
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ref_code VARCHAR(64) NOT NULL DEFAULT '',
			bill_code VARCHAR(64) NOT NULL DEFAULT '',
			qr_key VARCHAR(32) NOT NULL DEFAULT '',
			bank_id VARCHAR(40) NOT NULL DEFAULT '',
			bin VARCHAR(20) NOT NULL DEFAULT '',
			account_number VARCHAR(64) NOT NULL DEFAULT '',
			account_name VARCHAR(191) NOT NULL DEFAULT '',
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			content VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			source VARCHAR(40) NOT NULL DEFAULT '',
			source_ref VARCHAR(100) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			payload_status VARCHAR(20) NOT NULL DEFAULT '',
			committed_ref VARCHAR(100) NOT NULL DEFAULT '',
			txn_desc TEXT NULL,
			txn_account VARCHAR(64) NOT NULL DEFAULT '',
			note TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at DATETIME NULL,
			paid_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY ref_code (ref_code),
			KEY bill_code (bill_code),
			KEY status (status),
			KEY source_ref (source, source_ref),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta($sql);
	}

	private static function now()
	{
		return current_time('mysql');
	}

	/**
	 * Tạo một yêu cầu thanh toán.
	 *
	 * @param array $args bank_id, bin, account_number, account_name, amount,
	 *                    source, source_ref, payload, expires_in (giây), note.
	 * @return array|WP_Error Bản ghi vừa tạo.
	 */
	public static function create(array $args)
	{
		global $wpdb;

		$amount = round((float) ($args['amount'] ?? 0), 2);
		if ($amount <= 0) {
			return new WP_Error('ttck_invalid_amount', __('Số tiền thanh toán không hợp lệ.', 'thanh-toan-chuyen-khoan'));
		}

		$bank_id = strtolower(sanitize_text_field((string) ($args['bank_id'] ?? '')));
		if ($bank_id === '') {
			return new WP_Error('ttck_missing_bank', __('Chưa chọn ngân hàng nhận tiền.', 'thanh-toan-chuyen-khoan'));
		}

		$expires_in = isset($args['expires_in']) ? (int) $args['expires_in'] : 0;
		if ($expires_in <= 0) {
			$expires_in = TTCKPayment::get_setting('payment_expire_minutes', 30) * MINUTE_IN_SECONDS;
		}

		$bill_code = sanitize_text_field((string) ($args['bill_code'] ?? ''));

		$now  = self::now();
		$data = array(
			'ref_code'       => '',
			'bill_code'      => $bill_code,
			'qr_key'         => ttck_generate_random_string(16),
			'bank_id'        => $bank_id,
			'bin'            => sanitize_text_field((string) ($args['bin'] ?? TTCK_Banks::bin($bank_id))),
			'account_number' => sanitize_text_field((string) ($args['account_number'] ?? '')),
			'account_name'   => sanitize_text_field((string) ($args['account_name'] ?? '')),
			'amount'         => $amount,
			'paid_amount'    => 0,
			'content'        => '',
			'status'         => self::STATUS_PENDING,
			'source'         => sanitize_key((string) ($args['source'] ?? '')),
			'source_ref'     => sanitize_text_field((string) ($args['source_ref'] ?? '')),
			'payload'        => isset($args['payload']) ? wp_json_encode($args['payload']) : null,
			'payload_status' => isset($args['payload']) ? 'pending' : '',
			'note'           => sanitize_textarea_field((string) ($args['note'] ?? '')),
			'created_by'     => get_current_user_id(),
			'created_at'     => $now,
			'updated_at'     => $now,
			// created_at/expires_at đều theo giờ local của site để so sánh trực tiếp được.
			'expires_at'     => date('Y-m-d H:i:s', strtotime($now) + $expires_in),
		);

		$inserted = $wpdb->insert(self::table(), $data);
		if (!$inserted) {
			return new WP_Error('ttck_db_error', __('Không tạo được yêu cầu thanh toán.', 'thanh-toan-chuyen-khoan'));
		}

		$id = (int) $wpdb->insert_id;

		// ref_code = <tiền tố>ID: đúng định dạng mà app ngân hàng/Telegram bóc tách.
		$prefix   = (string) TTCKPayment::get_setting(array('bank_transfer', 'transaction_prefix'), '');
		$ref_code = $prefix . $id;

		/*
		 * Nội dung chuyển khoản:
		 *  - Có bill_code (đơn từ POS, dù nội dung không còn in mã phiếu — cột
		 *    bill_code vẫn lưu để tra cứu/đối soát nội bộ): nội dung ngắn gọn
		 *    "TT QR cho CT TGS - <tên shop>" do TTCK_API dựng, ưu tiên nhận diện
		 *    cửa hàng thay vì mã phiếu (nhiều app ngân hàng cắt nội dung dài).
		 *  - Không có bill_code: giữ hành vi cũ (nội dung = <tiền tố>ID) để app
		 *    ngân hàng / Telegram tự bóc tách và xác nhận.
		 */
		if ($bill_code !== '' && is_callable(array('TTCK_API', 'build_transfer_content'))) {
			$content = TTCK_Banks::ascii(TTCK_API::build_transfer_content($bill_code));
		} else {
			$content = TTCK_Banks::ascii(TTCKPayment::transaction_text($ref_code, null));
		}

		$wpdb->update(
			self::table(),
			array('ref_code' => $ref_code, 'content' => $content, 'updated_at' => self::now()),
			array('id' => $id)
		);

		return self::get($id);
	}

	public static function get($id)
	{
		global $wpdb;

		$id = (int) $id;
		if ($id <= 0) {
			return null;
		}

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	public static function get_by_ref($ref_code)
	{
		global $wpdb;

		$ref_code = trim((string) $ref_code);
		if ($ref_code === '') {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE ref_code = %s ORDER BY id DESC LIMIT 1', $ref_code),
			ARRAY_A
		);

		return $row ? self::hydrate($row) : null;
	}

	/**
	 * Tìm yêu cầu thanh toán theo mã phiếu bán (bill_code).
	 *
	 * Ưu tiên bản còn treo (pending) và mới nhất — mã phiếu có thể xuất hiện ở
	 * nhiều bản ghi nếu nhân viên bấm "Tạo mã mới" vài lần cho cùng một đơn.
	 */
	public static function get_by_bill_code($bill_code)
	{
		global $wpdb;

		$bill_code = trim((string) $bill_code);
		if ($bill_code === '') {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE bill_code = %s
				 ORDER BY (status = 'pending') DESC, id DESC LIMIT 1",
				$bill_code
			),
			ARRAY_A
		);

		return $row ? self::hydrate($row) : null;
	}

	public static function find_by_source($source, $source_ref, $status = '')
	{
		global $wpdb;

		$source     = sanitize_key((string) $source);
		$source_ref = (string) $source_ref;
		if ($source_ref === '') {
			return null;
		}

		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE source = %s AND source_ref = %s';
		$params = array($source, $source_ref);

		if ($status !== '') {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}

		$sql .= ' ORDER BY id DESC LIMIT 1';

		$row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	public static function update($id, array $fields)
	{
		global $wpdb;

		$id = (int) $id;
		if ($id <= 0 || empty($fields)) {
			return false;
		}

		$fields['updated_at'] = self::now();

		return false !== $wpdb->update(self::table(), $fields, array('id' => $id));
	}

	/**
	 * Ghi nhận một giao dịch tiền về cho yêu cầu thanh toán.
	 *
	 * @param int    $id
	 * @param float  $paid_amount Số tiền thực nhận.
	 * @param array  $txn         description, account.
	 * @return array|WP_Error Bản ghi sau khi cập nhật.
	 */
	public static function settle($id, $paid_amount, array $txn = array())
	{
		$payment = self::get($id);
		if (!$payment) {
			return new WP_Error('ttck_not_found', __('Không tìm thấy yêu cầu thanh toán.', 'thanh-toan-chuyen-khoan'));
		}

		if ($payment['status'] === self::STATUS_CANCELLED) {
			return new WP_Error('ttck_cancelled', __('Yêu cầu thanh toán đã bị huỷ.', 'thanh-toan-chuyen-khoan'));
		}

		// Quá hạn thì KHÔNG settle nữa — quét lại mã QR cũ cũng không ăn tiền vào đơn.
		if (self::maybe_expire($payment)) {
			return new WP_Error('ttck_expired', __('Yêu cầu thanh toán đã hết hạn.', 'thanh-toan-chuyen-khoan'));
		}

		if ($payment['status'] === self::STATUS_EXPIRED) {
			return new WP_Error('ttck_expired', __('Yêu cầu thanh toán đã hết hạn.', 'thanh-toan-chuyen-khoan'));
		}

		if ($payment['status'] === self::STATUS_PAID) {
			return new WP_Error('ttck_already_paid', __('Yêu cầu thanh toán đã được xử lý trước đó.', 'thanh-toan-chuyen-khoan'), $payment);
		}

		$paid_amount = round((float) $paid_amount, 2);
		$tolerance   = abs((float) TTCKPayment::get_setting(array('bank_transfer', 'acceptable_difference'), 0));
		$is_enough   = $paid_amount >= ((float) $payment['amount'] - $tolerance);

		$fields = array(
			'paid_amount' => $paid_amount,
			'status'      => $is_enough ? self::STATUS_PAID : self::STATUS_UNDERPAID,
			'txn_desc'    => isset($txn['description']) ? (string) $txn['description'] : '',
			'txn_account' => isset($txn['account']) ? sanitize_text_field((string) $txn['account']) : '',
			'paid_at'     => $is_enough ? self::now() : null,
		);

		self::update($payment['id'], $fields);
		$payment = self::get($payment['id']);

		$is_overpaid = $is_enough && $paid_amount > ((float) $payment['amount'] + $tolerance);

		/**
		 * Cho phép plugin khác (tgs_pos...) phản ứng khi tiền về.
		 */
		if ($is_enough) {
			do_action('ttck_payment_paid', $payment, $is_overpaid);
		} else {
			do_action('ttck_payment_underpaid', $payment);
		}
		do_action('ttck_payment_status_changed', $payment);

		return $payment;
	}

	/**
	 * Xác nhận thủ công (nhân viên bấm "Đã thanh toán").
	 */
	public static function mark_paid_manually($id, $note = '')
	{
		$payment = self::get($id);
		if (!$payment) {
			return new WP_Error('ttck_not_found', __('Không tìm thấy yêu cầu thanh toán.', 'thanh-toan-chuyen-khoan'));
		}

		if ($payment['status'] === self::STATUS_PAID) {
			return $payment;
		}

		self::update($payment['id'], array(
			'status'      => self::STATUS_PAID,
			'paid_amount' => $payment['amount'],
			'paid_at'     => self::now(),
			'note'        => trim($payment['note'] . "\n" . $note),
		));

		$payment = self::get($payment['id']);

		do_action('ttck_payment_paid', $payment, false);
		do_action('ttck_payment_status_changed', $payment);

		return $payment;
	}

	public static function cancel($id, $note = '')
	{
		$payment = self::get($id);
		if (!$payment || $payment['status'] === self::STATUS_PAID) {
			return false;
		}

		self::update($payment['id'], array(
			'status' => self::STATUS_CANCELLED,
			'note'   => trim($payment['note'] . "\n" . $note),
		));

		do_action('ttck_payment_status_changed', self::get($payment['id']));

		return true;
	}

	public static function set_payload($id, $payload, $status = 'pending')
	{
		return self::update($id, array(
			'payload'        => is_string($payload) ? $payload : wp_json_encode($payload),
			'payload_status' => sanitize_key($status),
		));
	}

	/**
	 * Đánh dấu payload đã được bên gọi xử lý xong (POS đã ghi phiếu bán).
	 */
	public static function mark_payload_committed($id, $committed_ref = '')
	{
		return self::update($id, array(
			'payload_status' => 'committed',
			'committed_ref'  => (string) $committed_ref,
		));
	}

	/**
	 * Danh sách giao dịch cho trang quản trị.
	 *
	 * @return array array('rows' => array, 'total' => int)
	 */
	public static function query(array $args = array())
	{
		global $wpdb;

		$args = wp_parse_args($args, array(
			'status'   => '',
			'search'   => '',
			'per_page' => 30,
			'page'     => 1,
		));

		$where  = array('1=1');
		$params = array();

		if ($args['status'] !== '') {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ($args['search'] !== '') {
			$like     = '%' . $wpdb->esc_like($args['search']) . '%';
			$where[]  = '(ref_code LIKE %s OR content LIKE %s OR source_ref LIKE %s OR account_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode(' AND ', $where);
		$per_page  = max(1, min(200, (int) $args['per_page']));
		$offset    = max(0, ((int) $args['page'] - 1) * $per_page);

		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where_sql;
		$total     = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

		$list_sql    = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$list_params = array_merge($params, array($per_page, $offset));
		$rows        = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);

		return array(
			'rows'  => array_map(array(__CLASS__, 'hydrate'), $rows ? $rows : array()),
			'total' => $total,
		);
	}

	/**
	 * Chuyển các yêu cầu quá hạn sang trạng thái hết hạn.
	 * Chạy nhẹ nên gọi trực tiếp trong cron hằng ngày.
	 */
	public static function expire_stale()
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			'UPDATE ' . self::table() . " SET status = %s, updated_at = %s
			 WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s",
			self::STATUS_EXPIRED,
			self::now(),
			self::STATUS_PENDING,
			self::now()
		));
	}

	/**
	 * Nếu bản ghi đang treo mà đã quá `expires_at` thì chuyển sang trạng thái
	 * hết hạn ngay (không chờ cron ngày). Trả về true nếu vừa chuyển.
	 *
	 * @param array $payment Bản ghi đã hydrate — sẽ được cập nhật tại chỗ.
	 */
	public static function maybe_expire(array &$payment)
	{
		if (($payment['status'] ?? '') !== self::STATUS_PENDING) {
			return false;
		}

		$expires_at = (string) ($payment['expires_at'] ?? '');
		if ($expires_at === '' || $expires_at === '0000-00-00 00:00:00') {
			return false;
		}

		if (strtotime($expires_at) >= strtotime(self::now())) {
			return false;
		}

		self::update((int) $payment['id'], array('status' => self::STATUS_EXPIRED));
		$payment['status']  = self::STATUS_EXPIRED;
		$payment['is_paid'] = false;

		do_action('ttck_payment_status_changed', self::get((int) $payment['id']));

		return true;
	}

	private static function hydrate(array $row)
	{
		$row['id']          = (int) $row['id'];
		$row['amount']      = (float) $row['amount'];
		$row['paid_amount'] = (float) $row['paid_amount'];
		$row['created_by']  = (int) $row['created_by'];
		$row['note']        = (string) $row['note'];
		$row['is_paid']     = ($row['status'] === self::STATUS_PAID);

		return $row;
	}
}
