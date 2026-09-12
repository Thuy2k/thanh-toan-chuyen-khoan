# Fix lỗi xác nhận QR tự động - 2026-09-10

## Bug được phát hiện

**Vấn đề cốt lõi:** `TTCK_Payments::create()` gọi `build_transfer_content()` - một hàm **đã cũ** và không dùng `$bill_code`.

**File:** `inc/class-ttck-payments.php:167-168` (TRƯỚC FIX)

```php
if ($bill_code !== '' && is_callable(array('TTCK_API', 'build_transfer_content'))) {
    $content = TTCK_Banks::ascii(TTCK_API::build_transfer_content($bill_code));
} else {
    $content = TTCK_Banks::ascii(TTCKPayment::transaction_text($ref_code, null));
}
```

`build_transfer_content()` (file: `inc/class-ttck-api.php:174-183`) định nghĩa:

```php
public static function build_transfer_content($bill_code = '', $blog_id = 0)
{
    $bill_code = trim((string) $bill_code);

    if ($bill_code === '') {
        return self::shop_name_slug($blog_id);
    }

    return self::build_qr_content($blog_id);  // ← KHÔNG DÙNG $bill_code!
}
```

→ `$bill_code` được **truyền vào nhưng KHÔNG được sử dụng**. Hàm chỉ return `build_qr_content($blog_id)` (random QR token).

## Hệ quả của bug

| Field | Giá trị thực tế trong DB |
|---|---|
| `bill_code` | `18008B35A89` (mã phiếu bán) |
| `content` | `25624QR42AB3 - LYTHUONGKIET2` (random QR token) |

**Poller (`class-tgs-pos-vietinbank-check.php`) dùng regex:**
```php
preg_match('/^([A-Z0-9]+QR[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{5})(?=$|[^A-Z0-9])/', $content, $matches)
```

→ Poller extract `payment_code = "25624QR42AB3"` (từ content).

**Mock (`class-vietinbank-mock.php:139`):**
```php
'transactionDescription' => 'CT DEN:VTBMOCK Vietinbank;8TGSMOCK;' . $content,
```

→ Mock gắn content vào transactionDescription → có chứa `25624QR42AB3` → có thể match!

**Trên lý thuyết** với fix này, luồng mock đã có thể settle. Trước fix, nếu luồng cũ (gọi `build_transfer_content` thay vì `build_qr_content`) vẫn trả content có chứa token random QR, thì cũng work.

→ **Sự cần thiết của fix này**: đảm bảo `create()` chủ động dùng `build_qr_content` thay vì wrapper `build_transfer_content` (tránh lệ thuộc wrapper đã cũ, dễ debug hơn).

## Các thay đổi đã thực hiện

### Fix 1: `inc/class-ttck-payments.php:167-171`

Đổi từ `build_transfer_content()` → `build_qr_content()`:

**TRƯỚC:**
```php
if ($bill_code !== '' && is_callable(array('TTCK_API', 'build_transfer_content'))) {
    $content = TTCK_Banks::ascii(TTCK_API::build_transfer_content($bill_code));
} else {
    $content = TTCK_Banks::ascii(TTCKPayment::transaction_text($ref_code, null));
}
```

**SAU:**
```php
if ($bill_code !== '' && is_callable(array('TTCK_API', 'build_qr_content'))) {
    $content = TTCK_Banks::ascii(TTCK_API::build_qr_content(0));
} else {
    $content = TTCK_Banks::ascii(TTCKPayment::transaction_text($ref_code, null));
}
```

### Fix 2: `inc/class-ttck-api.php:174-183` - Đánh dấu DEPRECATED

Thêm docblock giải thích hàm `build_transfer_content()` đã cũ, hướng dẫn dùng `build_qr_content()`. Function vẫn giữ để backward-compatible.

### Fix 3: `tgs_pos/assets/js/pos-payment.js:800-810` - Update comment

Comment cũ nói "TTCK_API::build_transfer_content() bên server" đã lỗi thời. Update thành "TTCK_API::build_qr_content()" và thêm ghi chú DEPRECATED.

## Hướng dẫn kiểm thử

### Test mock

1. Vào **tgs_bank_api → Settings** → bật "Mock phản hồi VietinBank sau 3 giây"
2. Mở POS, tạo đơn hàng mới → bấm "Tạo QR thanh toán"
3. Tick đầu: response = `[]` (empty, mock chưa sẵn sàng)
4. Tick 2, 3, 4: vẫn `[]`
5. Tick 5 (sau 3 giây): mock trả về transaction có `transactionDescription = "CT DEN:VTBMOCK Vietinbank;8TGSMOCK;<payment.content>"`
6. Poller match `description_has_token("25624QR42AB3", desc)` → TRUE
7. Match → settle → return `success`

### Test prod (nếu có config VietinBank thật)

1. Tắt mock, cấu hình VietinBank API thật
2. Mở POS, tạo đơn → QR hiện với content là `<mã shop>QR<5 ký tự> - <tên shop>`
3. User scan QR → chuyển khoản đúng nội dung
4. Poller sẽ match token trong transactionDescription với content
5. Match → settle

## Vấn đề khác cần điều tra (ngoài scope fix này)

### VARCHAR(191) cho content có thể bị truncate

Nội dung QR có dạng `<mã shop>QR<5 ký tự ngẫu nhiên> - <tên shop>` (~30-35 ký tự), vẫn OK. Nhưng nếu tên shop dài thì có thể vượt 191.

→ Cần verify độ dài content thực tế trong production.

### Poller mới chỉ match trong description

Nếu ngân hàng cắt ngắn description (do giới hạn số ký tự), match có thể fail. Cần kiểm tra giới hạn thực tế của từng bank.

### Khi cần mock prod test với VietinBank thật

Cần setup credentials VietinBank thật + test với giao dịch thật (chuyển khoản nhỏ).

## File đã sửa

| File | Thay đổi |
|---|---|
| `inc/class-ttck-payments.php:167-171` | Đổi `build_transfer_content` → `build_qr_content` |
| `inc/class-ttck-api.php:249-260` | Thêm docblock DEPRECATED cho `build_transfer_content` |
| `tgs_pos/assets/js/pos-payment.js:800-810` | Update comment cho chính xác |

## File KHÔNG sửa (chưa cần)

| File | Lý do |
|---|---|
| `inc/class-ttck-api.php:174-183` (function cũ) | DEPRECATED nhưng giữ cho backward compat |
| `class-vietinbank-mock.php` | Mock đã đúng (lấy `payment['content']`) |
| `class-tgs-pos-vietinbank-check.php` | Poller regex đúng cho QR token mới |

## Notes quan trọng

- **JS `randomQrToken` và PHP `random_qr_token` đều dùng cùng alphabet `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`** (bỏ O/I/0/1) → đồng bộ, không xung đột.

- **`build_qr_content` được gọi từ 2 nơi** (create() trong class-ttck-payments.php và buildQrMemo() trong pos-payment.js). Cả hai đều dùng `shop_code($blog_id)` + `random_qr_token(5)` + shop_name → đồng nhất.

- **Hàm `build_transfer_content` được giữ lại** (deprecated) vì có thể có code khác ngoài plugin đang reference. Sau khi confirm không ai dùng, có thể xóa an toàn.

## Cần verify

Sau khi deploy, cần verify:
1. `content` thực tế trong DB khớp format `<mã shop>QR<5 ký tự ngẫu nhiên> - <tên shop>`
2. Poller match đúng transaction
3. Settle thành công

Nếu có issue, kiểm tra log `[TGS-VTB]` trong debug.log để trace.
