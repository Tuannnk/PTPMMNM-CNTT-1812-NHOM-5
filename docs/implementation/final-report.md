# Báo cáo triển khai

Ngày: 01/11/2026

## Đã triển khai

- Migration chuỗi cung ứng và phân cấp kho.
- Nhà cung cấp và liên kết nhà cung cấp với phiếu nhập.
- Điểm bán, kho phụ trách và scope tài khoản điểm bán.
- Đơn phân phối, giao hàng, xác nhận nhận hàng và tồn điểm bán.
- Ownership `COMPANY_OWNED`, `SOLD`, `CONSIGNMENT` trong ledger.
- Menu, trang quản trị, tồn điểm bán và lọc dữ liệu theo scope.
- Regression fixture không còn phụ thuộc ID kiểm kê cố định.

## File chính

Migration: `database/migrate-supply-chain.sql`, `database/migrations/001_supply_chain.sql`.

Service: `src/SupplierService.php`, `src/SalesPointService.php`, `src/SalesOrderService.php`.

Trang: `public/suppliers.php`, `public/sales-points.php`, `public/sales-orders.php`, `public/sales-order.php`, `public/sales-stock.php`.

## Kiểm thử

| Nhóm | Trạng thái | Bằng chứng |
|---|---|---|
| PHP lint | PASS | `LINT=PASS` |
| Regression tests | PASS | `TESTS=20 FAILED=0` |
| Migration lần đầu | PASS | `MIGRATION_OK` |
| Migration chạy lại | PASS | `MIGRATION_REPEAT_OK` |
| SQL diff check | PASS | `git diff --check` |
| Dữ liệu mẫu mới | NOT RUN | Không tự tạo dữ liệu nghiệp vụ |
| HTTP integration | NOT RUN | Chưa có fixture web cô lập |
| PostgreSQL concurrency | NOT RUN | Cần hai connection trong staging |
| Backup/restore rehearsal | BLOCKED | Chưa xác định database mục tiêu và nơi lưu backup |

## Hướng dẫn triển khai

1. Backup và diễn tập restore database.
2. Chạy `database/schema.sql` nếu database chưa có schema nền.
3. Chạy `database/migrations/001_supply_chain.sql` bằng `psql`.
4. Kiểm tra kho `CENTRAL-01` và ba kho `REGIONAL` trước khi mở nghiệp vụ.
5. Tạo supplier, sales point và tài khoản scope tương ứng.
6. Chạy `bash app.sh lint` và `bash app.sh test`.

## Còn tồn tại

- Các đường cũ vẫn ép số lượng về integer; cần chuyển sang fixed-point trước khi dùng đơn vị thập phân.
- Nghiệp vụ bán hàng/xuất sử dụng/trả hàng/kiểm kê riêng tại điểm bán chưa có endpoint đầy đủ.
- Chưa có test HTTP, rollback tích hợp và concurrency thực tế.
- Cần rà soát lại ownership trong service giao nhận trước khi đưa vào production; migration đã hỗ trợ cột ownership nhưng không tự tạo dữ liệu.
