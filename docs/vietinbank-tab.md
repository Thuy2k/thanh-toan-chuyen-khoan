# Tab "VietinBank API" — Cấu hình + Test

> **Mã nguồn:**
> - `inc/class-ttck-vietinbank-api.php` — HTTP wrapper (`login` + `search_transactions`)
> - `inc/class-ttck-admin-page.php` — render admin tab + 2 AJAX handler test
> - `assets/css/style.css` — scope `.ttck-vtb-*`
> - `assets/js/ttck-vietinbank.js` — Test login / Test search + Copy curl

## MỤC ĐÍCH

Tab "VietinBank API" cho phép admin:
1. **Cấu hình 5 field bắt buộc** để gọi VietinBank MAP Merchant Portal:
   - Client ID
   - Username
   - Mật khẩu (được SHA-256 hash trước khi lưu)
   - Signature
   - Merchant ID
2. **Test trực tiếp** 2 endpoint:
   - **API 1**: Đăng nhập → lấy Bearer token
   - **API 2**: Truy vấn giao dịch (cần token từ API 1)
3. **Copy curl** — sinh câu lệnh curl tương đương để chạy ngoài terminal test.

## VỊ TRÍ

- **Menu**: Thanh toán Quét Mã QR (top-level của plugin này)
- **Submenu**: VietinBank API (cuối cùng, ngay sau "Xuất cấu hình")
- **Capability**: `manage_network_options` trên multisite (giống tab "Xuất cấu hình" — vì chứa secret)
- **URL**: `wp-admin/admin.php?page=ttck-vietinbank`

## 5 FIELD LƯU DB

| Key | Default | Mã hoá |
|---|---|---|
| `vietinbank_client_id` | `''` | Plain |
| `vietinbank_username` | `''` | Plain |
| `vietinbank_password_hash` | `''` | **SHA-256 hex64 ký tự** (admin nhập plain, plugin hash trước khi lưu) |
| `vietinbank_signature` | `''` | Plain |
| `vietinbank_merchant_id` | `''` | Plain |

### Luật lưu password

```
admin gõ plain "MatKhauCuaToi"
        │
        ▼
save_vietinbank() → hash('sha256', $plain) → "e1438...fc72"
        │
        ▼
update_option('ttck[vietinbank_password_hash]', "e1438...fc72")
```

DB không bao giờ chứa plain text. Admin gõ lại plain để đổi.

### Validation

| Field | Validate |
|---|---|
| 4 field text (trừ password) | Bắt buộc, lỗi "Thiếu: Client ID, ..." nếu rỗng |
| Password | 3 nhánh: gõ mới → hash + lưu / trống + DB có hash → giữ / trống + DB rỗng → lỗi "Chưa có mật khẩu" |

## 4 FIELD KHÔNG LƯU (default cứng trong code)

Không lưu DB — caller (POS, cron, test tab) có thể truyền để override, không thì class dùng const `DEFAULT_*`:

| Tên param | Const default | Nguồn |
|---|---|---|
| `captcha_resp` | `'123456'` | `TTCK_VietinBank_API::DEFAULT_CAPTCHA_RESP` |
| `ip_address` | `'118.70.124.48'` | `TTCK_VietinBank_API::DEFAULT_IP_ADDRESS` |
| `language` | `'vi'` | `TTCK_VietinBank_API::DEFAULT_LANGUAGE` |
| `timeout` | `30` (giây) | `TTCK_VietinBank_API::DEFAULT_TIMEOUT` |

## 2 AJAX TEST ENDPOINT

### `tgs_pos_vtb_check_payment` — không có, đây là của plugin khác

Trong tab này có 2 AJAX endpoint:

### `ttss_vtb_test_vietinbank_login` — Test login

**Input:** `nonce, captcha_resp, ip_address, language`

**Server làm:**
1. Đọc 5 key config từ `wp_options['ttck']`
2. Nếu thiếu → error "Chưa cấu hình đủ: ..."
3. Gọi `TTCK_VietinBank_API::login($args)` với `password_hash` lấy từ DB
4. Rút `accessToken` từ response (tìm trong các key: `accessToken`, `access_token`, `token`, `id_token`, `jsonWebToken`)
5. Nếu tìm được → lưu vào **transient `tgs_pos_vtb_token`** (TTL 1 giờ) — share giữa các session
6. Return `{ message, response: { status, data, raw }, token_preview }`

### `ttck_test_vietinbank_search` — Test search

**Input:** `nonce, start_date, end_date, search_type, page, size, sort, language`

**Server làm:**
1. Đọc config (chỉ cần `vietinbank_client_id`, `vietinbank_merchant_id`)
2. Lấy token từ transient `tgs_pos_vtb_token`
3. Nếu không có → error "Chưa có token. Bấm Test login trước."
4. Gọi `TTCK_VietinBank_API::search_transactions($args)`
5. Return `{ message, data: { status, data, raw }, token_preview }`

## CẤU TRÚC RESPONSE VIETINBANK (THỰC TẾ)

API 2 trả về `data.list` (mảng các giao dịch). Mỗi phần tử:

```json
{
  "id": "bf63e9a45ff302258a1264876b75f965",
  "tranTime": "05/09/2026 16:44:50",
  "amount": "268000",
  "transactionNumber": "2026248054389",
  "transactionDescription": "CT DEN:320T269078EQJM9M Vietinbank;8TGS8DC;TT QR cho CT THE GIOI SUA TGSBAOHA",
  "reqCardName": "LY THI HA",
  "reqCardNo": "8808205194330",
  "virtualAccount": "8TGS8DC",
  "bankBranch": "Agribank_NH NN va PTNT Viet Nam",
  "txnReference": "320T269078EQJM9M",
  ...
}
```

⚠️ **Lưu ý thực tế**: `transactionDescription` thường **KHÔNG chứa bill_code** của POS do inter-bank strip. Matcher ở `tgs_pos/docs/vietinbank-polling.md` xử lý case này bằng fallback "amount-only → warning".

## COPY CURL

Mỗi API có nút **Copy curl** sinh câu lệnh curl tương đương với Test. Curl được build từ config + input runtime, dùng `navigator.clipboard.writeText()` với fallback `execCommand('copy')`.

Vd login curl:

```bash
curl -X POST 'https://map.vietinbank.vn/vtb/public/map/api/ma/no-auth/login' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/plain, */*' \
  -H 'ClientId: <client_id>' \
  -H 'Signature: <signature>' \
  -H 'Origin: https://map.vietinbank.vn' \
  -H 'Referer: https://map.vietinbank.vn/merchant/ma/login' \
  -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) ...' \
  -d '{"username":"<username>","password":"<hash>","captcha_resp":"...","device":{...},"ip_address":"...","language":"..."}'
```

Search curl có placeholder `<BEARER_TOKEN>` vì token nằm server-side (transient), JS không đọc được.

## CLASS `TTCK_VietinBank_API`

```php
class TTCK_VietinBank_API {
    const LOGIN_URL  = 'https://map.vietinbank.vn/vtb/public/map/api/ma/no-auth/login';
    const SEARCH_URL = 'https://map.vietinbank.vn/vtb/public/map/api/rpt-txnmng/api/ma/payment-transaction/search';
    const DEFAULT_CAPTCHA_RESP = '123456';
    const DEFAULT_IP_ADDRESS   = '118.70.124.48';
    const DEFAULT_LANGUAGE     = 'vi';
    const DEFAULT_TIMEOUT      = 30;

    public function login($args = []);
    public function search_transactions($args = []);
}
```

Mọi method trả mảng chuẩn:

```php
['ok' => bool, 'status' => int, 'data' => array|null, 'raw' => string, 'error' => string]
```

## FILE LIÊN QUAN

| File | Vai trò |
|---|---|
| `inc/class-ttck-vietinbank-api.php` | HTTP wrapper (login, search) + const DEFAULT_* |
| `inc/class-ttck-admin-page.php` | render_vietinbank_tab + save_vietinbank + 2 AJAX test |
| `assets/css/style.css` | CSS scope `.ttck-vtb-*` (toast, pulse, animation) |
| `assets/js/ttck-vietinbank.js` | Poller tab + Toast + Copy curl |
| `ttck.php` | `$default_settings` có 5 key mới + restore_masked_secrets whitelist |
| `../tgs_pos/docs/vietinbank-polling.md` | Consumer dùng API này để auto-settle |

## TIÊU THỤ

### tgs_pos (đang chạy)

Xem `../tgs_pos/docs/vietinbank-polling.md` — tgs_pos gọi `TTCK_VietinBank_API` trực tiếp để auto-settle payment khi khách chuyển khoản.

### Plugin khác

```php
$api = new TTCK_VietinBank_API();
$resp = $api->login([
    'client_id'     => 'abc...',
    'signature'     => 'def...',
    'username'      => '0865645199',
    'password_hash' => hash('sha256', 'plain_password'),  // hoặc gửi 'password_raw' → class tự hash
    // optional override defaults:
    'captcha_resp' => '654321',
    'ip_address'   => '203.0.113.5',
    'language'     => 'en',
    'timeout'      => 60,
]);

if ($resp['ok']) {
    $token = $resp['data']['accessToken'] ?? '';
    // dùng $token cho API 2...
}
```

---

**End of doc — vietinbank-tab.md**