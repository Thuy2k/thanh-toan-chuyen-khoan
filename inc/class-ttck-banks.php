<?php
/**
 * Danh mục ngân hàng + sinh mã VietQR.
 *
 * Trước đây mỗi ngân hàng là một class WC_Payment_Gateway riêng (inc/banks/*.php)
 * và BIN nằm trong TTCKPayment::get_list_bin(). Toàn bộ đã gộp về đây để plugin
 * chạy độc lập, không cần WooCommerce.
 */

if (!defined('ABSPATH')) {
	exit;
}

class TTCK_Banks
{
	/**
	 * Danh mục ngân hàng.
	 *
	 * key   = bank_id (trùng tên file icon trong /assets/<bank_id>.png)
	 * bin   = mã BIN Napas dùng cho VietQR ('' nếu không hỗ trợ VietQR)
	 * label = tên hiển thị
	 * qr    = kiểu sinh QR: 'vietqr' | 'momo' | 'viettelpay' | 'none'
	 */
	private static $banks = array(
		'abbank'           => array('bin' => '970425', 'label' => 'ABBANK'),
		'acb'              => array('bin' => '970416', 'label' => 'ACB'),
		'agribank'         => array('bin' => '970405', 'label' => 'Agribank'),
		'bacabank'         => array('bin' => '970409', 'label' => 'BacABank'),
		'baovietbank'      => array('bin' => '970438', 'label' => 'BaoVietBank'),
		'bidv'             => array('bin' => '970418', 'label' => 'BIDV'),
		'cake'             => array('bin' => '546034', 'label' => 'CAKE by VPBank'),
		'cimbbank'         => array('bin' => '422589', 'label' => 'CIMB Bank'),
		'dongabank'        => array('bin' => '970406', 'label' => 'DongA Bank'),
		'eximbank'         => array('bin' => '970431', 'label' => 'Eximbank'),
		'hdbank'           => array('bin' => '970437', 'label' => 'HDBank'),
		'hsbc'             => array('bin' => '458761', 'label' => 'HSBC Việt Nam'),
		'kienlongbank'     => array('bin' => '970452', 'label' => 'KienLongBank'),
		'lienviet'         => array('bin' => '970449', 'label' => 'LPBank'),
		'mbbank'           => array('bin' => '970422', 'label' => 'MB Bank'),
		'msb'              => array('bin' => '970426', 'label' => 'MSB'),
		'namabank'         => array('bin' => '970428', 'label' => 'Nam A Bank'),
		'ncb'              => array('bin' => '970419', 'label' => 'NCB'),
		'ocb'              => array('bin' => '970448', 'label' => 'OCB'),
		'oceanbank'        => array('bin' => '970414', 'label' => 'OceanBank'),
		'pulicbank'        => array('bin' => '970439', 'label' => 'Public Bank'),
		'pvcombank'        => array('bin' => '970412', 'label' => 'PVcomBank'),
		'sacombank'        => array('bin' => '970403', 'label' => 'Sacombank'),
		'saigonbank'       => array('bin' => '970400', 'label' => 'SaigonBank'),
		'scb'              => array('bin' => '970429', 'label' => 'SCB'),
		'seabank'          => array('bin' => '970440', 'label' => 'SeABank'),
		'shb'              => array('bin' => '970443', 'label' => 'SHB'),
		'techcombank'      => array('bin' => '970407', 'label' => 'Techcombank'),
		'timoplus'         => array('bin' => '963388', 'label' => 'Timo Plus'),
		'tnex'             => array('bin' => '963369', 'label' => 'Tnex'),
		'tpbank'           => array('bin' => '970423', 'label' => 'TPBank'),
		'vietabank'        => array('bin' => '970427', 'label' => 'VietABank'),
		'vietbank'         => array('bin' => '970433', 'label' => 'VietBank'),
		'vietcapitalbank'  => array('bin' => '970454', 'label' => 'BVBank'),
		'vietcombank'      => array('bin' => '970436', 'label' => 'Vietcombank'),
		'vietinbank'       => array('bin' => '970415', 'label' => 'VietinBank'),
		'vib'              => array('bin' => '970441', 'label' => 'VIB'),
		'vpbank'           => array('bin' => '970432', 'label' => 'VPBank'),
		'vrbank'           => array('bin' => '970421', 'label' => 'VRB'),
		'vnpay'            => array('bin' => '970437', 'label' => 'VNPay QR'),
		'vinid'            => array('bin' => '',       'label' => 'VinID', 'qr' => 'none'),
		'momo'             => array('bin' => '',       'label' => 'MoMo', 'qr' => 'momo'),
		'viettelpay'       => array('bin' => '971005', 'label' => 'Viettel Money', 'qr' => 'viettelpay'),
	);

	/**
	 * @return array bank_id => array('bin','label','qr')
	 */
	public static function all()
	{
		$banks = array();
		foreach (self::$banks as $id => $bank) {
			$banks[$id] = array(
				'bin'   => (string) $bank['bin'],
				'label' => (string) $bank['label'],
				'qr'    => isset($bank['qr']) ? $bank['qr'] : 'vietqr',
			);
		}

		/**
		 * Cho phép bổ sung ngân hàng mới mà không phải sửa plugin.
		 */
		return apply_filters('ttck_banks', $banks);
	}

	public static function exists($bank_id)
	{
		$banks = self::all();
		return isset($banks[strtolower((string) $bank_id)]);
	}

	public static function get($bank_id)
	{
		$banks   = self::all();
		$bank_id = strtolower((string) $bank_id);

		if (!isset($banks[$bank_id])) {
			return array('bin' => '', 'label' => strtoupper($bank_id), 'qr' => 'none');
		}

		return $banks[$bank_id];
	}

	public static function label($bank_id)
	{
		$bank = self::get($bank_id);
		return $bank['label'];
	}

	public static function bin($bank_id)
	{
		$bank = self::get($bank_id);
		return $bank['bin'];
	}

	/**
	 * URL icon của ngân hàng. Trả về '' nếu chưa có file ảnh.
	 */
	public static function icon_url($bank_id)
	{
		$bank_id = strtolower((string) $bank_id);
		if ($bank_id === '' || !file_exists(TTCK_DIR . 'assets/' . $bank_id . '.png')) {
			return '';
		}

		return TTCK_URL . 'assets/' . $bank_id . '.png';
	}

	/**
	 * Dựng chuỗi EMVCo (VietQR) để tự sinh ảnh QR tại chỗ, không phụ thuộc api.vietqr.io.
	 *
	 * @param string $bin        BIN Napas của ngân hàng thụ hưởng.
	 * @param string $account_no Số tài khoản thụ hưởng.
	 * @param int    $amount     Số tiền (VND). 0 = QR tĩnh (khách tự nhập).
	 * @param string $content    Nội dung chuyển khoản.
	 * @return string Chuỗi payload đã gắn CRC, sẵn sàng encode thành ảnh QR.
	 */
	public static function vietqr_payload($bin, $account_no, $amount = 0, $content = '')
	{
		$bin        = preg_replace('/[^0-9]/', '', (string) $bin);
		$account_no = preg_replace('/[^0-9A-Za-z]/', '', (string) $account_no);
		$amount     = (int) $amount;
		$content    = self::ascii($content);

		if ($bin === '' || $account_no === '') {
			return '';
		}

		// 38 - Merchant Account Information (NAPAS/VietQR)
		$beneficiary = self::tlv('00', $bin) . self::tlv('01', $account_no);
		$merchant    = self::tlv('00', 'A000000727')
			. self::tlv('01', $beneficiary)
			. self::tlv('02', 'QRIBFTTA');

		$payload = self::tlv('00', '01')
			. self::tlv('01', $amount > 0 ? '12' : '11')   // 12 = QR động (một lần)
			. self::tlv('38', $merchant)
			. self::tlv('53', '704')                        // VND
			. ($amount > 0 ? self::tlv('54', (string) $amount) : '')
			. self::tlv('58', 'VN');

		if ($content !== '') {
			// 62-08 = Purpose of Transaction (nội dung chuyển khoản)
			$payload .= self::tlv('62', self::tlv('08', substr($content, 0, 99)));
		}

		$payload .= '6304';

		return $payload . strtoupper(str_pad(dechex(self::crc16($payload)), 4, '0', STR_PAD_LEFT));
	}

	/**
	 * Tag-Length-Value theo chuẩn EMVCo.
	 */
	private static function tlv($tag, $value)
	{
		$value = (string) $value;
		return $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
	}

	/**
	 * CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF) theo yêu cầu của EMVCo.
	 */
	private static function crc16($data)
	{
		$crc = 0xFFFF;
		$len = strlen($data);

		for ($i = 0; $i < $len; $i++) {
			$crc ^= (ord($data[$i]) << 8) & 0xFFFF;
			for ($bit = 0; $bit < 8; $bit++) {
				$crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
			}
		}

		return $crc & 0xFFFF;
	}

	/**
	 * Ngân hàng chỉ nhận nội dung không dấu; bỏ luôn ký tự đặc biệt cho an toàn.
	 */
	public static function ascii($text)
	{
		$text = remove_accents((string) $text);
		$text = preg_replace('/[^A-Za-z0-9 ._\-]/', ' ', $text);
		return trim(preg_replace('/\s+/', ' ', $text));
	}
}
