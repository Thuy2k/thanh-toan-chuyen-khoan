<?php

/**
 * FILE JSON TÀI KHOẢN NHẬN TIỀN — NGUỒN CẤP TÀI KHOẢN DUY NHẤT LÚC CHẠY.
 *
 * ── VÌ SAO KHÔNG ĐỌC DB NỮA ─────────────────────────────────────────────────
 *
 * Số tài khoản nhận tiền của 650 shop trước đây nằm trong option `ttck` của
 * từng subsite. Ai có `manage_options` ở một shop, hoặc ai vào được DB, là đổi
 * được số tài khoản đó — và mã QR shop đưa cho khách quét sẽ trỏ sang tài
 * khoản khác. Không có dấu vết gì, không ai biết cho tới lúc đối soát.
 *
 * Nay đường đi tách làm hai:
 *
 *   Màn cấu hình  ──ghi──>  DB (option `ttck`)      ← soạn thảo, sửa thoải mái
 *   Người tin cậy ──xuất──> file JSON này            ← chốt, khoá cứng ở server
 *   Lúc sinh QR   ──đọc──>  file JSON này            ← KHÔNG bao giờ đọc DB
 *
 * Nhờ vậy:
 *   • Sửa được DB vẫn KHÔNG đổi được tài khoản khách quét — file mới là thứ
 *     quyết định, mà file thì khoá ở tầng hệ điều hành.
 *   • DB lệch file là DẤU HIỆU: có người vừa đổi cấu hình mà chưa (hoặc không
 *     được phép) chốt. Màn "Xuất cấu hình" đối chiếu và chỉ mặt từng shop.
 *   • Xuất ra một file phẳng thì soi 650 shop bằng mắt được, thấy ngay tài
 *     khoản lạ hoặc hai shop trùng số tài khoản.
 *
 * ── FILE NÀY KHÔNG PHẢI BÍ MẬT ──────────────────────────────────────────────
 *
 * Số tài khoản hiện trên mã QR cho mọi khách quét, in cả trên bill — đọc được
 * nó không cho kẻ tấn công thêm quyền gì. Giá trị của file nằm ở chỗ nó KHÔNG
 * SỬA ĐƯỢC, không phải ở chỗ không đọc được. Dù vậy vẫn nên chặn truy cập từ
 * internet: danh sách đầy đủ 650 shop là nguyên liệu tốt cho lừa đảo. Xem hàm
 * check_public_exposure() và tài liệu docs/file-tai-khoan-json.md.
 *
 * @package thanh-toan-chuyen-khoan
 */

if (!defined('ABSPATH')) {
	exit;
}

class TTCK_Account_File
{
	/** Cấu trúc file đổi thì tăng số này lên */
	const FORMAT_VERSION = 1;

	/** Nhớ nội dung file trong một request, tránh đọc đĩa nhiều lần */
	private static $cache = null;

	/* ---------------------------------------------------------------------
	 * Đường dẫn
	 * ------------------------------------------------------------------ */

	/**
	 * Đường dẫn file JSON.
	 *
	 * Mặc định nằm trong plugin để khoá cùng một chỗ với mã nguồn. Lọc
	 * `ttck_account_file_path` để dời ra ngoài webroot — an toàn hơn, và
	 * KHÔNG bị mất khi cập nhật plugin.
	 */
	public static function path()
	{
		$default = TTCK_DIR . 'data/bank-accounts.json';

		return (string) apply_filters('ttck_account_file_path', $default);
	}

	/**
	 * URL công khai của file — chỉ dùng để KIỂM TRA xem có bị lộ không.
	 *
	 * Dời file bằng lọc `ttck_account_file_path` thì ở đây không đoán được URL
	 * nữa. Nhưng "không đoán được" khác hẳn "chắc chắn an toàn": dời sang một
	 * chỗ khác mà vẫn nằm trong webroot thì vẫn lộ y như cũ. Nên mở thêm lọc
	 * để khai URL mới, chứ đừng im lặng coi như đã xong.
	 */
	public static function public_url()
	{
		$default = (self::path() === TTCK_DIR . 'data/bank-accounts.json')
			? TTCK_URL . 'data/bank-accounts.json'
			: '';

		return (string) apply_filters('ttck_account_file_public_url', $default);
	}

	/* ---------------------------------------------------------------------
	 * Đọc — đường đi của tiền
	 * ------------------------------------------------------------------ */

	/**
	 * Đọc toàn bộ file. Trả về mảng đã giải mã, hoặc null nếu không đọc được.
	 *
	 * Không đọc được thì trả null chứ KHÔNG rơi về DB: rơi về DB là mở lại
	 * đúng cái cửa vừa đóng.
	 */
	public static function read($force = false)
	{
		if (!$force && self::$cache !== null) {
			return self::$cache === false ? null : self::$cache;
		}

		$path = self::path();

		if (!is_readable($path)) {
			self::$cache = false;
			return null;
		}

		$raw = file_get_contents($path);
		if ($raw === false || trim($raw) === '') {
			self::$cache = false;
			return null;
		}

		/*
		 * Cắt BOM UTF-8 nếu có.
		 *
		 * json_decode() coi ba byte EF BB BF ở đầu là rác và trả về null —
		 * tức là CẢ 650 SHOP mất kênh chuyển khoản, chỉ vì ai đó mở file bằng
		 * Notepad rồi bấm lưu. Cái giá của việc bỏ qua ba byte thừa nhỏ hơn
		 * nhiều so với cái giá của việc dừng bán.
		 */
		$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['shops']) || !is_array($data['shops'])) {
			self::$cache = false;
			return null;
		}

		self::$cache = $data;

		return $data;
	}

	/**
	 * Tài khoản nhận tiền của MỘT shop, lấy theo blog_id.
	 *
	 * Tra đúng blog_id của site đang chạy nên dù file có đủ 650 shop thì shop
	 * này cũng không thể vô tình (hay cố ý) dùng tài khoản của shop khác.
	 *
	 * @return array [bank_id => ['account_number','account_name','title','enabled','sort']]
	 */
	public static function accounts_for_blog($blog_id = 0)
	{
		$blog_id = (int) ($blog_id ?: get_current_blog_id());
		$data    = self::read();

		if ($data === null) {
			return array();
		}

		$shop = $data['shops'][(string) $blog_id] ?? null;
		if (!is_array($shop) || !isset($shop['accounts']) || !is_array($shop['accounts'])) {
			return array();
		}

		return $shop['accounts'];
	}

	/**
	 * Tên shop (trường `name`) theo blog_id, đọc từ chính file JSON đã chốt.
	 *
	 * Dùng để dựng hậu tố nội dung chuyển khoản (TGS<tên shop>). Không có trong
	 * file thì trả '' để bên gọi tự rơi về get_bloginfo('name').
	 */
	public static function shop_name_for_blog($blog_id = 0)
	{
		$blog_id = (int) ($blog_id ?: get_current_blog_id());
		$data    = self::read();

		if ($data === null) {
			return '';
		}

		$shop = $data['shops'][(string) $blog_id] ?? null;

		return is_array($shop) ? trim((string) ($shop['name'] ?? '')) : '';
	}

	/**
	 * Vì sao shop này chưa có tài khoản — để ghi log cho ra hồn.
	 *
	 * "Chưa cấu hình tài khoản" và "file cấu hình đang mất" là hai chuyện khác
	 * hẳn nhau, mà nhìn từ màn bán hàng thì giống nhau y hệt.
	 */
	public static function why_empty($blog_id = 0)
	{
		$blog_id = (int) ($blog_id ?: get_current_blog_id());
		$path    = self::path();

		if (!file_exists($path)) {
			return sprintf('Chưa có file tài khoản (%s). Vào Thanh toán QR > Xuất cấu hình để tạo.', $path);
		}

		if (!is_readable($path)) {
			return sprintf('File tài khoản %s không đọc được — kiểm tra quyền file.', $path);
		}

		if (self::read() === null) {
			return sprintf('File tài khoản %s hỏng hoặc sai định dạng JSON.', $path);
		}

		return sprintf('Shop này (blog_id %d) chưa có trong file tài khoản — cần xuất lại.', $blog_id);
	}

	/* ---------------------------------------------------------------------
	 * Gom dữ liệu từ DB của cả mạng
	 * ------------------------------------------------------------------ */

	/**
	 * Đọc cấu hình tài khoản của MỌI site trong mạng, thẳng từ bảng options.
	 *
	 * Cố ý KHÔNG dùng switch_to_blog(): với 650 site thì mỗi lần chuyển là một
	 * lần dựng lại cache, nạp lại option của site đó — chậm gấp nhiều lần một
	 * câu SELECT chỉ lấy đúng một dòng.
	 *
	 * @return array [blog_id => ['blog_id','site_code','domain','path','name','accounts']]
	 */
	public static function collect_from_db()
	{
		global $wpdb;

		$shops = array();

		foreach (self::network_sites() as $site) {
			$blog_id = (int) $site['blog_id'];
			$table   = $wpdb->get_blog_prefix($blog_id) . 'options';

			$raw = $wpdb->get_var($wpdb->prepare(
				"SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1",
				'ttck'
			));

			$settings = is_string($raw) ? maybe_unserialize($raw) : null;
			$accounts = self::accounts_from_settings(is_array($settings) ? $settings : array());

			$shops[$blog_id] = array(
				'blog_id'   => $blog_id,
				'site_code' => (string) $site['site_code'],
				'domain'    => (string) $site['domain'],
				'path'      => (string) $site['path'],
				'name'      => (string) $site['name'],
				'accounts'  => $accounts,
			);
		}

		return $shops;
	}

	/**
	 * Rút phần tài khoản ra khỏi mảng settings của một site.
	 *
	 * Giữ đúng những trường quyết định tiền chảy đi đâu, cộng nhãn hiển thị.
	 * Mọi thứ khác trong option `ttck` (token, telegram, webhook…) là chuyện
	 * riêng của site, không thuộc file này.
	 */
	private static function accounts_from_settings(array $settings)
	{
		$raw_accounts = isset($settings['bank_transfer_accounts']) && is_array($settings['bank_transfer_accounts'])
			? $settings['bank_transfer_accounts']
			: array();
		$meta = isset($settings['bank_meta']) && is_array($settings['bank_meta'])
			? $settings['bank_meta']
			: array();

		$out = array();

		foreach ($raw_accounts as $bank_id => $rows) {
			$bank_id = strtolower(trim((string) $bank_id));
			if ($bank_id === '' || !is_array($rows) || empty($rows)) {
				continue;
			}

			$first = null;
			foreach ($rows as $row) {
				if (is_array($row) && trim((string) ($row['account_number'] ?? '')) !== '') {
					$first = $row;
					break;
				}
			}
			if ($first === null) {
				continue;
			}

			$bank_meta = isset($meta[$bank_id]) && is_array($meta[$bank_id]) ? $meta[$bank_id] : array();

			$out[$bank_id] = array(
				'bank_id'        => $bank_id,
				'bin'            => (string) TTCK_Banks::bin($bank_id),
				'account_number' => trim((string) $first['account_number']),
				'account_name'   => trim((string) ($first['account_name'] ?? '')),
				'title'          => trim((string) ($bank_meta['title'] ?? '')),
				'enabled'        => isset($bank_meta['enabled']) ? ('yes' === $bank_meta['enabled']) : false,
				'sort'           => (int) ($bank_meta['sort'] ?? 0),
			);
		}

		ksort($out);

		return $out;
	}

	/**
	 * Danh sách site trong mạng, kèm mã shop nếu bảng blogs có cột đó.
	 *
	 * Site đơn (không bật multisite) thì trả về đúng một dòng — cùng một mã
	 * chạy được cả hai kiểu cài đặt.
	 */
	private static function network_sites()
	{
		global $wpdb;

		if (!is_multisite()) {
			return array(array(
				'blog_id'   => get_current_blog_id(),
				'site_code' => '',
				'domain'    => (string) wp_parse_url(home_url(), PHP_URL_HOST),
				'path'      => '/',
				'name'      => get_bloginfo('name'),
			));
		}

		// Cột tgs_site_code do hệ thống TGS thêm vào, cài đặt khác không có.
		$has_code = (bool) $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->blogs} LIKE 'tgs_site_code'");
		$code_col = $has_code ? 'tgs_site_code' : "'' AS tgs_site_code";

		$rows = $wpdb->get_results(
			"SELECT blog_id, domain, path, {$code_col}
			 FROM {$wpdb->blogs}
			 WHERE deleted = 0 AND spam = 0 AND archived = 0
			 ORDER BY blog_id ASC",
			ARRAY_A
		) ?: array();

		$sites = array();
		foreach ($rows as $row) {
			$blog_id = (int) $row['blog_id'];
			$sites[] = array(
				'blog_id'   => $blog_id,
				'site_code' => (string) ($row['tgs_site_code'] ?? ''),
				'domain'    => (string) $row['domain'],
				'path'      => (string) $row['path'],
				'name'      => (string) get_blog_option($blog_id, 'blogname', ''),
			);
		}

		return $sites;
	}

	/* ---------------------------------------------------------------------
	 * Xuất file
	 * ------------------------------------------------------------------ */

	/** Dựng nội dung file (chưa ghi) — dùng chung cho xuất file và tải về máy */
	public static function build_payload()
	{
		$shops = self::collect_from_db();
		$user  = wp_get_current_user();

		// Khoá theo chuỗi: JSON không có khái niệm khoá số, giữ chuỗi cho khớp
		$shops_out = array();
		foreach ($shops as $blog_id => $shop) {
			$shops_out[(string) $blog_id] = $shop;
		}

		$payload = array(
			'format_version' => self::FORMAT_VERSION,
			'generated_at'   => current_time('mysql'),
			'generated_by'   => array(
				'user_id' => get_current_user_id(),
				'login'   => $user ? (string) $user->user_login : '',
			),
			'network'        => (string) wp_parse_url(network_home_url(), PHP_URL_HOST),
			'shop_count'     => count($shops_out),
			'account_count'  => array_sum(array_map(static function ($shop) {
				return count($shop['accounts']);
			}, $shops_out)),
			/*
			 * Tổng kiểm chỉ để phát hiện file bị sửa tay hoặc hỏng dở chừng.
			 * KHÔNG phải chữ ký: ai sửa được file thì cũng tính lại được số
			 * này. Thứ chặn sửa file là khoá ở tầng hệ điều hành, không phải
			 * dòng dưới đây.
			 */
			'checksum'       => hash('sha256', wp_json_encode($shops_out)),
			'shops'          => $shops_out,
		);

		return $payload;
	}

	/** Dựng JSON đã format cho người đọc */
	public static function build_json()
	{
		return wp_json_encode(
			self::build_payload(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Ghi file JSON.
	 *
	 * @return array|WP_Error ['path','bytes','shop_count','account_count']
	 */
	public static function export()
	{
		$path = self::path();
		$dir  = dirname($path);

		if (!wp_mkdir_p($dir)) {
			return new WP_Error('ttck_file_dir', sprintf('Không tạo được thư mục %s.', $dir));
		}

		self::protect_directory($dir);

		$payload = self::build_payload();
		$json    = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ($json === false) {
			return new WP_Error('ttck_file_encode', 'Không dựng được JSON từ cấu hình hiện tại.');
		}

		/*
		 * File đang khoá cứng (chattr +i) thì ghi hỏng — đây là trạng thái
		 * MONG MUỐN lúc chạy thật, nên báo cho rõ chứ đừng coi là lỗi hệ thống.
		 */
		if (file_exists($path) && !is_writable($path)) {
			return new WP_Error(
				'ttck_file_locked',
				sprintf('File %s đang khoá, không ghi được. Mở khoá ở server rồi xuất lại.', $path)
			);
		}

		$bytes = file_put_contents($path, $json, LOCK_EX);
		if ($bytes === false) {
			return new WP_Error('ttck_file_write', sprintf('Không ghi được file %s.', $path));
		}

		self::$cache = null;

		return array(
			'path'          => $path,
			'bytes'         => (int) $bytes,
			'shop_count'    => (int) $payload['shop_count'],
			'account_count' => (int) $payload['account_count'],
		);
	}

	/**
	 * Chặn truy cập thư mục từ trình duyệt.
	 *
	 * .htaccess chỉ có tác dụng với Apache. Server chạy nginx thì file này vô
	 * nghĩa — phải chặn trong cấu hình nginx, xem docs/file-tai-khoan-json.md.
	 * Nút "Kiểm tra lộ file" trong màn quản trị nói thẳng đang lộ hay không.
	 */
	private static function protect_directory($dir)
	{
		$htaccess = $dir . '/.htaccess';
		if (!file_exists($htaccess)) {
			@file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
		}

		$index = $dir . '/index.php';
		if (!file_exists($index)) {
			@file_put_contents($index, "<?php\n// Im lặng là vàng.\n");
		}
	}

	/* ---------------------------------------------------------------------
	 * Soi bất thường
	 * ------------------------------------------------------------------ */

	/**
	 * Trạng thái file — để màn quản trị hiện ra cho người ta nhìn.
	 */
	public static function status()
	{
		$path = self::path();
		$data = self::read(true);

		return array(
			'path'          => $path,
			'exists'        => file_exists($path),
			'readable'      => is_readable($path),
			// Ghi được lúc chạy thật là ĐÁNG LO: nghĩa là chưa khoá ở server.
			'writable'      => file_exists($path) ? is_writable($path) : is_writable(dirname($path)),
			'size'          => file_exists($path) ? (int) filesize($path) : 0,
			'modified_at'   => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : '',
			'valid'         => $data !== null,
			'generated_at'  => (string) ($data['generated_at'] ?? ''),
			'generated_by'  => (string) ($data['generated_by']['login'] ?? ''),
			'shop_count'    => (int) ($data['shop_count'] ?? 0),
			'account_count' => (int) ($data['account_count'] ?? 0),
			'checksum'      => (string) ($data['checksum'] ?? ''),
			/*
			 * Tính lại tổng kiểm từ chính phần shops trong file. Lệch nghĩa là
			 * có người sửa tay nội dung mà quên (hoặc không biết) cập nhật số
			 * này — dấu hiệu đầu tiên đáng đi hỏi.
			 */
			'checksum_ok'   => $data === null
				? false
				: hash('sha256', wp_json_encode($data['shops'])) === (string) ($data['checksum'] ?? ''),
		);
	}

	/**
	 * Đối chiếu DB với file, từng shop một.
	 *
	 * Đây là chỗ phát hiện "có người vừa đổi tài khoản trong DB". File là thứ
	 * đang thật sự cấp tài khoản, nên DB lệch file KHÔNG làm mất tiền — nhưng
	 * nó cho biết ai đó vừa động vào cấu hình.
	 *
	 * @return array ['rows' => [...], 'summary' => [...]]
	 */
	public static function compare()
	{
		$db_shops   = self::collect_from_db();
		$data       = self::read(true);
		$file_shops = is_array($data) ? ($data['shops'] ?? array()) : array();

		$rows = array();

		foreach ($db_shops as $blog_id => $shop) {
			$in_file = $file_shops[(string) $blog_id]['accounts'] ?? array();
			$in_db   = $shop['accounts'];

			$rows[] = array(
				'blog_id'   => $blog_id,
				'site_code' => $shop['site_code'],
				'name'      => $shop['name'],
				'domain'    => $shop['domain'] . $shop['path'],
				'db'        => $in_db,
				'file'      => is_array($in_file) ? $in_file : array(),
				'state'     => self::row_state($in_db, is_array($in_file) ? $in_file : array()),
			);
		}

		/*
		 * Shop có trong file mà DB không còn — site vừa bị xoá hoặc đổi id.
		 * Vẫn phải hiện ra, không thì nó nằm im trong file mãi mãi.
		 */
		foreach ($file_shops as $blog_key => $shop) {
			if (isset($db_shops[(int) $blog_key])) {
				continue;
			}

			$rows[] = array(
				'blog_id'   => (int) $blog_key,
				'site_code' => (string) ($shop['site_code'] ?? ''),
				'name'      => (string) ($shop['name'] ?? ''),
				'domain'    => (string) ($shop['domain'] ?? ''),
				'db'        => array(),
				'file'      => is_array($shop['accounts'] ?? null) ? $shop['accounts'] : array(),
				'state'     => 'orphan',
			);
		}

		$duplicates = self::find_duplicate_accounts($rows);

		$summary = array(
			'total'      => count($rows),
			'ok'         => 0,
			'diff'       => 0,
			'missing'    => 0,
			'empty'      => 0,
			'orphan'     => 0,
			'duplicates' => count($duplicates),
		);

		foreach ($rows as $row) {
			if (isset($summary[$row['state']])) {
				$summary[$row['state']]++;
			}
		}

		return array(
			'rows'       => $rows,
			'summary'    => $summary,
			'duplicates' => $duplicates,
		);
	}

	/** Trạng thái một dòng đối chiếu */
	private static function row_state(array $db, array $file)
	{
		if (empty($db) && empty($file)) {
			return 'empty';
		}

		if (empty($file)) {
			return 'missing';   // DB có, file chưa có → shop này chưa quét QR được
		}

		return self::same_accounts($db, $file) ? 'ok' : 'diff';
	}

	/**
	 * Hai bộ tài khoản có giống nhau không.
	 *
	 * Chỉ so những trường quyết định tiền chảy đi đâu, cộng bật/tắt. Đổi mỗi
	 * nhãn hiển thị thì không đáng gọi là bất thường.
	 */
	private static function same_accounts(array $a, array $b)
	{
		if (array_keys($a) !== array_keys($b)) {
			return false;
		}

		foreach ($a as $bank_id => $acc) {
			$other = $b[$bank_id] ?? array();

			foreach (array('account_number', 'account_name', 'enabled') as $field) {
				$left  = $field === 'enabled' ? (bool) ($acc[$field] ?? false) : (string) ($acc[$field] ?? '');
				$right = $field === 'enabled' ? (bool) ($other[$field] ?? false) : (string) ($other[$field] ?? '');

				if ($left !== $right) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Hai shop trở lên dùng chung một số tài khoản.
	 *
	 * Gần như luôn là hậu quả của việc nhân bản site để tạo shop mới: cấu hình
	 * đi theo bản sao, và tiền của shop mới chảy về shop cũ. Soi theo file vì
	 * file mới là thứ đang cấp tài khoản.
	 */
	private static function find_duplicate_accounts(array $rows)
	{
		$seen = array();

		foreach ($rows as $row) {
			foreach ($row['file'] as $acc) {
				$number = trim((string) ($acc['account_number'] ?? ''));
				if ($number === '') {
					continue;
				}

				$key = strtolower((string) ($acc['bank_id'] ?? '')) . ':' . $number;
				$seen[$key][] = array(
					'blog_id'   => $row['blog_id'],
					'site_code' => $row['site_code'],
					'name'      => $row['name'],
				);
			}
		}

		$dups = array();
		foreach ($seen as $key => $shops) {
			if (count($shops) > 1) {
				$dups[$key] = $shops;
			}
		}

		return $dups;
	}

	/**
	 * File có tải được từ internet không.
	 *
	 * Tự gọi vào chính URL của mình. 200 nghĩa là ai cũng tải được danh sách
	 * tài khoản của cả 650 shop — phải chặn ngay ở tầng web server.
	 */
	public static function check_public_exposure()
	{
		$url = self::public_url();

		if ($url === '') {
			return array('checked' => false, 'reason' => 'File đã dời ra ngoài thư mục plugin.');
		}

		if (!file_exists(self::path())) {
			return array('checked' => false, 'reason' => 'Chưa có file để kiểm tra.');
		}

		$res = wp_remote_get($url, array(
			'timeout'   => 10,
			'sslverify' => false,
			// Không để bộ nhớ đệm trả lời thay
			'headers'   => array('Cache-Control' => 'no-cache'),
		));

		if (is_wp_error($res)) {
			return array(
				'checked' => false,
				'reason'  => 'Không tự gọi được vào ' . $url . ': ' . $res->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		// 200 mà nội dung đúng là JSON của mình thì chắc chắn đang lộ
		$leaking = ($code === 200 && strpos($body, '"format_version"') !== false);

		return array(
			'checked' => true,
			'url'     => $url,
			'code'    => $code,
			'leaking' => $leaking,
		);
	}
}
