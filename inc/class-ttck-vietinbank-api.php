<?php
/**
 * HTTP client cho VietinBank MAP Merchant Portal.
 *
 * Hai endpoint lấy từ Postman collection "New Collection.postman_collection.json":
 *   - POST .../ma/no-auth/login              → lấy accessToken
 *   - POST .../payment-transaction/search     → truy vấn giao dịch
 *
 * Tham số cố ý CHIA LÀM 2 LOẠI:
 *
 *   1. REQUIRED, lưu wp_options['ttck']:
 *      - client_id, username, password_hash (SHA-256 hex), signature, merchant_id.
 *      - Admin nhập qua tab "VietinBank API" — không có default. Nếu trống, caller
 *        phải tự quyết định (thường là không gọi được).
 *
 *   2. OPTIONAL, có default cứng trong const:
 *      - captcha_resp = "123456", ip_address = "118.70.124.48",
 *        language = "vi", timeout = 30.
 *      - wp_parse_args() sẽ tự lấy const DEFAULT_* khi caller không truyền.
 *      - Caller có thể truyền giá trị khác để override (vd test ở IP khác).
 *
 * Mọi method public trả mảng chuẩn:
 *   [
 *     'ok'      => bool,
 *     'status'  => int HTTP status (0 nếu không gọi được),
 *     'data'    => body decode JSON | null,
 *     'raw'     => body thô (string),
 *     'error'   => thông báo lỗi (chỉ khi !ok),
 *   ]
 */

if (!defined('ABSPATH')) {
	exit;
}

class TTCK_VietinBank_API
{
	const LOGIN_URL  = 'https://map.vietinbank.vn/vtb/public/map/api/ma/no-auth/login';
	const SEARCH_URL = 'https://map.vietinbank.vn/vtb/public/map/api/rpt-txnmng/api/ma/payment-transaction/search';

	/*
	 * Default cứng — lấy từ curl mẫu trong Postman. Chỉ dùng khi caller KHÔNG
	 * truyền giá trị vào $args. Không lưu DB, không hiện trên form — admin
	 * có thể override bằng cách truyền từ code (cron, importer, …).
	 */
	const DEFAULT_CAPTCHA_RESP = '123456';
	const DEFAULT_IP_ADDRESS   = '118.70.124.48';
	const DEFAULT_LANGUAGE     = 'vi';
	const DEFAULT_TIMEOUT      = 30;

	/**
	 * POST /vtb/public/map/api/ma/no-auth/login
	 *
	 * @param array $args
	 *   - 'client_id'    (string, REQUIRED — lấy từ DB nếu gọi từ admin)
	 *   - 'signature'    (string, REQUIRED — lấy từ DB)
	 *   - 'username'     (string, REQUIRED)
	 *   - 'password_raw' (string — plain text; class tự SHA-256 trước khi gửi).
	 *                    Hoặc truyền 'password_hash' đã hash sẵn, class ưu tiên cái này.
	 *   - 'captcha_resp' (string, optional — default "123456")
	 *   - 'ip_address'   (string, optional — default "118.70.124.48")
	 *   - 'language'     (string, optional — default "vi")
	 *   - 'timeout'      (int,    optional — default 30 giây)
	 */
	public function login($args = array())
	{
		$args = wp_parse_args($args, array(
			'client_id'    => '',
			'signature'    => '',
			'username'     => '',
			'password_raw' => '',
			'password_hash'=> '',
			'captcha_resp' => self::DEFAULT_CAPTCHA_RESP,
			'ip_address'   => self::DEFAULT_IP_ADDRESS,
			'language'     => self::DEFAULT_LANGUAGE,
			'timeout'      => self::DEFAULT_TIMEOUT,
		));

		/*
		 * Ưu tiên password_hash nếu caller đã hash sẵn (vd đọc từ DB). Nếu
		 * không có thì hash từ password_raw. Cuối cùng vẫn gửi hash — đúng
		 * giao thức server đang yêu cầu.
		 */
		$password_hex = (string) $args['password_hash'] !== ''
			? (string) $args['password_hash']
			: hash('sha256', (string) $args['password_raw']);

		$body = array(
			'username'     => $args['username'],
			'password'     => $password_hex,
			'captcha_resp' => $args['captcha_resp'],
			'device'       => array(
				'os'       => array('name' => null, 'version' => null),
				'browser'  => array('name' => null, 'version' => null),
				'location' => array('long' => 0, 'lat' => 0),
			),
			'ip_address'   => $args['ip_address'],
			'language'     => $args['language'],
		);

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json, text/plain, */*',
			'ClientId'     => $args['client_id'],
			'Signature'    => $args['signature'],
			'Origin'       => 'https://map.vietinbank.vn',
			'Referer'      => 'https://map.vietinbank.vn/merchant/ma/login',
			'User-Agent'   => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
			                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15',
		);

		return $this->post_json(self::LOGIN_URL, $body, $headers, (int) $args['timeout']);
	}

	/**
	 * POST /vtb/public/map/api/rpt-txnmng/api/ma/payment-transaction/search
	 *
	 * @param array $args
	 *   - 'token'       (string, REQUIRED — Bearer lấy từ login)
	 *   - 'client_id'   (string, REQUIRED — header ClientId)
	 *   - 'merchant_id' (string, REQUIRED — header merchantId)
	 *   - 'start_date'  (string, REQUIRED — dd/MM/yyyy)
	 *   - 'end_date'    (string, REQUIRED — dd/MM/yyyy)
	 *   - 'search_type' (string, optional — "0" mặc định; "1" = đã ghi có)
	 *   - 'page'        (int,    optional — 0)
	 *   - 'size'        (int,    optional — 20)
	 *   - 'sort'        (string, optional — "txnDate,desc")
	 *   - 'language'    (string, optional — "vi")
	 *   - 'timeout'     (int,    optional — 30)
	 */
	public function search_transactions($args = array())
	{
		$args = wp_parse_args($args, array(
			'token'       => '',
			'client_id'   => '',
			'merchant_id' => '',
			'language'    => self::DEFAULT_LANGUAGE,
			'start_date'  => '',
			'end_date'    => '',
			'search_type' => '0',
			'page'        => 0,
			'size'        => 20,
			'sort'        => 'txnDate,desc',
			'timeout'     => self::DEFAULT_TIMEOUT,
		));

		$url = add_query_arg(array(
			'page' => (int) $args['page'],
			'size' => (int) $args['size'],
			'sort' => $args['sort'],
		), self::SEARCH_URL);

		$body = array(
			'searchType' => (string) $args['search_type'],
			'startDate'  => $args['start_date'],
			'endDate'    => $args['end_date'],
		);

		$headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json, text/plain, */*',
			'ClientId'      => $args['client_id'],
			'merchantId'    => $args['merchant_id'],
			'x-lang'        => $args['language'],
			'Authorization' => 'Bearer ' . $args['token'],
			'Origin'        => 'https://map.vietinbank.vn',
			'Referer'       => 'https://map.vietinbank.vn/trx-mgmt/ma/transaction-management/payment-transactions',
			'User-Agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
			                 . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15',
		);

		return $this->post_json($url, $body, $headers, (int) $args['timeout']);
	}

	/**
	 * POST JSON helper — trả mảng chuẩn {ok, status, data, raw, error}.
	 */
	private function post_json($url, $body, $headers, $timeout = 30)
	{
		$headers['Content-Length'] = strlen(wp_json_encode($body));

		$resp = wp_remote_post($url, array(
			'headers'   => $headers,
			'body'      => wp_json_encode($body),
			'timeout'   => max(5, (int) $timeout),
			'sslverify' => true,
		));

		if (is_wp_error($resp)) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => null,
				'raw'    => '',
				'error'  => $resp->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code($resp);
		$raw    = (string) wp_remote_retrieve_body($resp);
		$data   = json_decode($raw, true);
		$ok     = $status >= 200 && $status < 300;

		return array(
			'ok'     => $ok,
			'status' => $status,
			'data'   => is_array($data) ? $data : null,
			'raw'    => $raw,
			'error'  => $ok ? '' : sprintf('HTTP %d — %s', $status, substr($raw, 0, 300)),
		);
	}
}