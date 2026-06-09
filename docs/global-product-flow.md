# Luồng sản phẩm global cho thanh toán chuyển khoản

Tài liệu này ghi lại kết quả rà soát plugin `thanh-toan-chuyen-khoan` theo chuẩn sản phẩm global.

## Kết luận hiện tại

- Plugin này là cổng thanh toán/QR cho WooCommerce.
- Luồng chính chỉ đọc `WC_Order`, tổng tiền, trạng thái đơn, phương thức thanh toán và cấu hình ngân hàng.
- Hiện plugin không query bảng sản phẩm TGS, không join `local_product_name`, không đọc `local_ledger_item`.
- Vì không có catalog sản phẩm trong plugin nên chưa có đoạn code cần chuyển sang global product.

## Các luồng chính đã rà

- `ttck.php`
  - REST webhook Telegram.
  - AJAX kiểm tra trạng thái thanh toán.
  - AJAX khách bấm xác nhận đã chuyển khoản.
  - Cập nhật trạng thái WooCommerce order sau khi nhận giao dịch.
- `inc/banks/class-ttck-base.php`
  - Sinh QR thanh toán ở thank-you page/email.
  - Đọc tổng tiền bằng `WC_Order::get_total()`.
  - Không đọc line item sản phẩm.
- `inc/functions.php`
  - Hiển thị thông tin ngân hàng ở danh sách/chi tiết đơn WooCommerce.
  - Không đọc catalog sản phẩm.

## Quy tắc nếu phát triển thêm

- Không thêm truy vấn `local_product_name` hoặc bảng sản phẩm local.
- Nếu cần hiển thị thông tin sản phẩm của đơn TGS/POS, lấy dòng nghiệp vụ từ nguồn chứng từ phù hợp rồi hydrate catalog qua `TGS_Global_Product_Source`.
- Nếu cần tìm kiếm/lấy danh sách sản phẩm, đọc tài liệu chuẩn tại:

```text
wp-content/plugins/tgs_shop_management/docs/global-product-api.md
```

- Các khóa legacy như `local_product_name_id` hoặc `local_product_sku`, nếu xuất hiện trong dữ liệu cũ, phải hiểu là alias của global product.
