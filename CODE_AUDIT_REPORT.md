# Báo cáo kiểm toán mã nguồn

**Ngày kiểm tra:** 2026-11-02  
**Phạm vi:** Toàn bộ source/configuration hiện có trong repository. Không sửa source, không xóa file và không chạy câu lệnh ghi vào database. Chỉ file báo cáo này được cập nhật.

## Tóm tắt

- Không tái hiện được lỗi làm lint hoặc test thất bại: lint đạt và bộ test hiện có đạt **27/27**.
- Có **1 lỗi MEDIUM đã xác minh** về việc làm mất phần lẻ của số lượng, nếu nghiệp vụ cần quản lý hàng theo đơn vị thập phân.
- Có **1 rủi ro MEDIUM về hiệu năng**: một số trang vẫn tải toàn bộ ledger vào PHP; chưa gây lỗi với dữ liệu demo nhưng tăng tuyến tính theo lịch sử.
- Có **1 rủi ro MEDIUM về kiểm kê** khi danh mục lô thay đổi trong lúc một đợt kiểm kê đang mở; chưa có kiểm thử cạnh tranh để xác minh tác động ở production.
- Không tìm thấy bằng chứng đủ chắc chắn cho lỗ hổng CRITICAL/HIGH trong các luồng đã đọc. Điều này không thay thế kiểm thử xâm nhập hoặc xác minh cấu hình triển khai.

## Phát hiện theo mức độ

### MEDIUM — Luồng tồn kho ép số lượng thập phân thành số nguyên

- **Trạng thái:** Lỗi thực tế khi lưu/đọc số lượng có phần lẻ; tác động nghiệp vụ phụ thuộc việc có mặt hàng dùng đơn vị thập phân hay không.
- **Vị trí:** [src/Store.php:147](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/Store.php:147), [src/StockLedger.php:17](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/StockLedger.php:17), [src/Validation.php:98](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/Validation.php:98), [src/Validation.php:144](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/Validation.php:144); schema số lượng dùng `numeric(14,3)`, ví dụ [database/migrate-supply-chain.sql:99](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/database/migrate-supply-chain.sql:99).
- **Nguyên nhân/bằng chứng:** `Store::normalize()` ép `qty`, `min_stock`, số tồn kiểm kê và số thực tế sang `(int)`; phép cộng tồn trong `StockLedger` cũng ép `(int)`. Các form validator dùng `FILTER_VALIDATE_INT`. Trong khi đó schema cho phép 3 chữ số thập phân. README cũng ghi nhận chưa hỗ trợ nghiệp vụ thập phân.
- **Tác động:** Giá trị như `1.750` bị đọc thành `1`; tồn, chênh lệch kiểm kê và giới hạn xuất có thể sai. Database có thể giữ số lẻ nhưng giao diện/tính toán làm mất phần lẻ.
- **Khắc phục:** Chọn rõ chính sách đơn vị: nếu chỉ hỗ trợ số nguyên, ràng buộc schema và dữ liệu để không chứa phần lẻ; nếu cần số lẻ, chuyển toàn bộ validation/tính toán sang fixed-point (ví dụ milli-unit integer) hoặc decimal chính xác, cập nhật form và kiểm thử tích lũy/so sánh.

### MEDIUM — Một số trang đọc toàn bộ sổ phát sinh vào bộ nhớ

- **Trạng thái:** Rủi ro hiệu năng/scalability có bằng chứng trong source; chưa quan sát thấy chậm với dữ liệu demo.
- **Vị trí:** [public/index.php:6](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/public/index.php:6), [public/stock.php:10](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/public/stock.php:10), [public/warehouses.php:17](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/public/warehouses.php:17), [public/sales-stock.php:8](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/public/sales-stock.php:8), [src/Store.php:25](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/Store.php:25).
- **Nguyên nhân:** Các trang gọi `Store::all('stock_movements')`; Store chạy `SELECT *` và `fetchAll()`, sau đó lọc/tổng hợp trên PHP. Dashboard cũng tải toàn bộ chứng từ và dòng tồn kiểm kê liên quan.
- **Tác động:** Thời gian truy vấn, bộ nhớ PHP và CPU tăng theo tổng ledger, kể cả khi trang chỉ hiển thị vài dòng hoặc một điểm bán. Khi dữ liệu lớn, request có thể chậm hoặc hết memory. Lịch sử/CSV lịch sử và danh sách chứng từ đã có xử lý SQL phân trang/streaming, nhưng chưa bao phủ các trang nêu trên.
- **Khắc phục:** Chuyển lọc, tổng hợp tồn và các số liệu dashboard sang truy vấn PostgreSQL theo phạm vi được phân quyền; chỉ lấy dữ liệu cần cho trang, thêm chỉ mục theo điều kiện truy vấn và đo trên dữ liệu lớn.

### MEDIUM — Lô tạo mới trong lúc kiểm kê mở không có dòng kiểm kê

- **Trạng thái:** Rủi ro nghiệp vụ có điều kiện; source xác nhận snapshot lô chỉ được tạo một lần lúc mở đợt, chưa có bằng chứng môi trường thật cho thấy danh mục lô thay đổi trong thời gian kiểm kê.
- **Vị trí:** [src/StockCountService.php:23](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/StockCountService.php:23), [src/StockCountService.php:49](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/StockCountService.php:49), [src/StockCountService.php:93](/home/os/labs/PTPMMNM-CNTT-1812-NHOM-5/src/StockCountService.php:93).
- **Nguyên nhân:** Lúc tạo kiểm kê, service snapshot các batch đang active và tạo `stock_count_lines`. Lúc đóng, service chỉ đọc và xử lý các dòng đã lưu. Không thấy khóa/điều kiện ngăn tạo batch hoặc phát sinh nhập hàng từ batch mới trong thời gian kiểm kê.
- **Tác động:** Nếu batch mới có tồn phát sinh sau snapshot nhưng trước khi đóng đợt, batch đó không được khai báo số thực tế và không được tính vào chênh lệch kiểm kê. Kết quả kiểm kê có thể thiếu hàng.
- **Khắc phục:** Quy định và thực thi snapshot/khóa danh mục trong suốt đợt; hoặc cập nhật bộ dòng kiểm kê khi batch phát sinh mới; hoặc chặn đóng nếu phát hiện tồn ở batch ngoài snapshot. Bổ sung test tích hợp mô phỏng nhận hàng/batch mới giữa thời điểm mở và đóng.

## Các hạng mục đã kiểm tra, chưa phát hiện lỗi tái hiện được

- **Đăng nhập và phân quyền:** Đã rà `src/Auth.php`, `src/LoginThrottle.php`, `public/login.php`, `public/access.php` và các điểm kiểm tra scope trong controller/service. Có CSRF cho form ghi, các truy vấn database chính dùng prepared statements, throttle đăng nhập dùng file lock. Không thực hiện kiểm thử brute-force, fuzzing hay kiểm thử cấu hình reverse proxy.
- **Chứng từ và tồn kho:** Đã rà validation, ledger, transfer, voucher, adjustment, count; các thao tác ghi nghiệp vụ được bọc transaction ở service. Bộ test xác nhận một số quy tắc xuất vượt tồn, chuyển kho, kiểm kê và request throttle. Chưa chạy concurrency/rollback/retry test trên database thử nghiệm.
- **Chuỗi cung ứng/điểm bán:** Đã rà các service và trang supplier, sales point, sales order, delivery, sales stock. Chưa chạy các thao tác ghi nghiệp vụ qua browser vì yêu cầu không thay đổi database; vì vậy không xác minh end-to-end trên database.
- **Lịch sử, chứng từ, CSV:** Đã đọc SQL phân trang và logic xuất; bộ test xác nhận tổng, thứ tự và giới hạn trang lịch sử, CSV formula marker. Chưa kiểm tra file CSV với mọi encoding/ứng dụng bảng tính.
- **Database/schema/migration:** Đã đọc schema, migration và seed supply-chain. Không chạy migration/seed. Database được kiểm tra chỉ bằng truy vấn SELECT trong lần rà soát trước; trạng thái migration hiện tại và tính nhất quán với mọi file schema chưa được xác minh lại bằng thao tác ghi.
- **Frontend:** PHP server-rendered, CSS và JavaScript trong source; không có cấu hình frontend build hoặc browser test tự động. Lint PHP không xác nhận accessibility hay tương thích trình duyệt.
- **API:** Không thấy REST/JSON API riêng; giao diện dùng trang PHP và form POST. Không có OpenAPI spec để kiểm tra.
- **Dependencies/config:** Không có `composer.json`/lock hoặc `package.json`/lock trong dự án; không có pipeline build/type-check được cấu hình. Không thể xác minh dependency ngoài môi trường triển khai. Không rà secrets/TLS/reverse proxy production.
- **TODO/FIXME:** Quét source, loại báo cáo khỏi kết quả, không tìm thấy TODO/FIXME/XXX trong mã.
- **Kiến trúc/bảo trì:** Phân lớp `public/`, `src/`, `templates/`, `database/` tách tương đối rõ; một số trang chuỗi cung ứng viết rất nén/dòng dài, làm tăng chi phí review và nguy cơ bỏ sót nhánh lỗi. Đây là vấn đề bảo trì, chưa phải lỗi runtime đã tái hiện.

## Lệnh kiểm tra và kết quả

- `bash app.sh check` — `MOI_TRUONG=SAN_SANG`.
- `bash app.sh lint` — `LINT=PASS`.
- `bash app.sh test` — `TESTS=27 FAILED=0`.
- `git diff --check` — đạt.
- Không chạy build/type-check riêng vì dự án không có cấu hình tương ứng. Không chạy migration hoặc thao tác ghi database.

## Giới hạn

Kết quả phản ánh source hiện có và test suite hiện tại. Chưa có kiểm thử bảo mật chuyên dụng, kiểm thử tải, kiểm thử trình duyệt, concurrency/integration trên database tách biệt, hoặc quyền truy cập cấu hình triển khai production. Các rủi ro có điều kiện được ghi là rủi ro, không khẳng định đã gây sai dữ liệu production.
