# File tài khoản JSON — nguồn cấp tài khoản nhận tiền

## Vì sao có

Số tài khoản nhận tiền của 650 shop trước đây nằm trong option `ttck` của từng
subsite. Ai có `manage_options` ở một shop, hoặc ai vào được DB, là đổi được số
tài khoản — và mã QR shop đưa cho khách quét sẽ trỏ sang tài khoản khác. Không
dấu vết, không ai biết cho tới lúc đối soát.

Nay đường đi tách làm hai:

```
Màn cấu hình   ──ghi──>  DB (option `ttck`)     ← soạn thảo, sửa thoải mái
Người tin cậy  ──chốt──> file JSON               ← khoá cứng ở server
Lúc sinh QR    ──đọc──>  file JSON               ← KHÔNG bao giờ đọc DB
```

Sửa được DB vẫn **không** đổi được đồng nào chảy đi đâu. Và DB lệch file là dấu
hiệu có người vừa động vào cấu hình — màn *Xuất cấu hình* chỉ mặt từng shop.

## File nằm ở đâu

```
wp-content/plugins/thanh-toan-chuyen-khoan/data/bank-accounts.json
```

Dời được bằng lọc `ttck_account_file_path`. **Nên dời ra ngoài webroot** nếu
tiện — vừa không lộ qua HTTP, vừa không mất khi cập nhật plugin:

```php
// wp-config.php hoặc một mu-plugin
add_filter('ttck_account_file_path', function () {
    return '/var/www/tgs-secure/bank-accounts.json';
});
```

## Quy trình chốt

1. Sửa tài khoản ở tab **Tài khoản ngân hàng** của shop → lưu vào DB.
   Lưu xong màn hình báo rõ: *chưa có hiệu lực*.
2. Mở khoá file ở server.
3. Vào **Thanh toán QR → Xuất cấu hình** → bấm **Chốt cấu hình**.
4. Khoá lại file.

```bash
chattr -i data/bank-accounts.json    # mở khoá
# ... bấm Chốt cấu hình ...
chattr +i data/bank-accounts.json    # khoá lại
```

Màn hình hiện sẵn trạng thái khoá. Đang chạy thật mà thấy *"CHƯA KHOÁ"* thì
tức là bước 4 bị quên.

## Ai được chốt

Chỉ **super admin toàn mạng** (`manage_network_options`). Cố ý hẹp hơn quyền
vào các tab khác, vì hai lẽ:

- Nếu admin từng shop cũng chốt được thì tấn công rút còn hai bước — sửa DB rồi
  bấm Chốt — và file lại khớp DB, mất sạch khả năng phát hiện.
- Bảng đối chiếu hiện tài khoản của **mọi** shop. Admin một shop không có việc
  gì phải nhìn thấy tài khoản của 649 shop còn lại.

## Chặn truy cập từ internet

Thư mục `data/` có sẵn `.htaccess`, nhưng **nginx không đọc `.htaccess`**. Server
chạy nginx (aaPanel) phải thêm vào cấu hình site:

```nginx
location ~* /wp-content/plugins/thanh-toan-chuyen-khoan/data/ {
    deny all;
    return 404;
}
```

Đừng tin là đã chặn — màn *Xuất cấu hình* tự gọi vào chính URL đó và nói thẳng
đang lộ hay không. Bấm **Kiểm tra lại** sau khi sửa cấu hình nginx.

Nói cho đúng mức: số tài khoản hiện trên QR cho mọi khách quét, in cả trên
bill — đọc được nó không cho kẻ tấn công thêm quyền gì. Giá trị của file nằm ở
chỗ nó **không sửa được**, không phải ở chỗ không đọc được. Vẫn nên chặn, vì
danh sách đầy đủ 650 shop là nguyên liệu tốt cho lừa đảo.

## Không có file thì sao

Shop **không quét QR được**, và log ghi rõ lý do (`[TTCK] Shop blog_id=… không
có tài khoản nhận tiền: …`). Tiền mặt, thẻ, voucher không ảnh hưởng.

Đây là lựa chọn có chủ ý: thà không nhận chuyển khoản còn hơn nhận vào tài
khoản người khác. **Đừng thêm nhánh rơi về DB khi thiếu file** — làm thế là mở
lại đúng cái cửa vừa đóng.

## Đọc bảng đối chiếu

| Trạng thái | Nghĩa là | Làm gì |
|---|---|---|
| Khớp | DB và file như nhau | không phải làm gì |
| **LỆCH** | DB khác file — **có người vừa đổi cấu hình** | đi hỏi ai đổi, rồi chốt hoặc trả lại |
| Chưa chốt | DB có, file chưa có | shop này chưa quét QR được, cần chốt |
| Chưa cấu hình | cả hai đều trống | shop chưa dùng chuyển khoản |
| Thừa trong file | file có, site không còn | site bị xoá, chốt lại cho sạch |

Riêng **"số tài khoản dùng chung cho nhiều shop"** hiện thành khối đỏ trên
cùng. Hầu như luôn do nhân bản site để tạo shop mới: cấu hình đi theo bản sao,
và tiền của shop mới chảy về tài khoản shop cũ. Gặp là phải sửa ngay.

## Tổng kiểm (checksum)

Trong file có trường `checksum` — sha256 của khối `shops`. Màn hình tính lại và
so. Lệch nghĩa là nội dung file bị sửa tay.

Nói rõ giới hạn: **đây không phải chữ ký**. Ai sửa được file thì cũng tính lại
được số này. Nó bắt sửa nhầm và hỏng file, không bắt được kẻ cố ý. Thứ chặn sửa
file là khoá ở tầng hệ điều hành.

## File có nên vào git không

Mặc định `data/.gitignore` bỏ qua nó. Hai lý do: không đưa danh sách tài khoản
650 shop vào lịch sử git, và không để lệnh deploy ghi đè bản đang khoá.

Muốn có lịch sử thay đổi thì tốt hơn là sao lưu file mỗi lần chốt vào một nơi
riêng, đừng dùng chính repo mã nguồn.
