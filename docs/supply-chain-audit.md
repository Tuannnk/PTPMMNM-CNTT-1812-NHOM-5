# Audit mở rộng chuỗi cung ứng và phân phối

Ngày: 01/11/2026. Phạm vi bàn giao: Giai đoạn 1, kèm kế hoạch cho Giai đoạn 2. Chưa triển khai chức năng, sửa schema hay chạy migration.

## 1. Phạm vi đã kiểm tra

Đã đọc README, CONTRIBUTING, toàn bộ 13 file `src/`, 16 trang PHP trong `public/`, templates, CSS, schema và các script SQL, bộ test, `app.sh`, công cụ cấu hình database. Đã đối chiếu danh sách bảng và cấu trúc kho với PostgreSQL bằng transaction `READ ONLY`. Không hiển thị hoặc ghi lại mật khẩu cấu hình, không nạp seed, không chỉnh dữ liệu nghiệp vụ.

Working tree sạch trước audit. Tài liệu này là file duy nhất được tạo trong audit.

## 2. Kiến trúc thực tế

- PHP thuần, hàm toàn cục và các lớp service tĩnh; không framework, Composer, router hoặc REST API riêng.
- Mỗi `public/*.php` là một endpoint: nạp `src/bootstrap.php`, xác thực, nhận GET/POST, gọi validation/service, render template. Các URL hiện tại cần giữ nguyên.
- `bootstrap.php`: session, CSRF, helper, nhãn trạng thái và nạp service. POST thành công chuyển hướng 303. `Input.php` cung cấp `field`, `intField`, `listField`, `e`, `qty`.
- `Database.php`: một kết nối PDO PostgreSQL, exception khi lỗi, native prepared statements; lấy cấu hình từ `.local/db.json`.
- `Store.php`: whitelist bảng, tải toàn bộ từng bảng rồi cache trong request; normalize ID/số lượng và gắn phạm vi kho cho user. Chưa có query repository phân trang hoặc scope tại SQL cho hầu hết danh sách.
- `public/warehouses.php`, `items.php`, `access.php` còn chứa validation và SQL ghi danh mục ngay trong trang. README mô tả việc tách nghiệp vụ khỏi giao diện chưa hoàn toàn khớp thực tế.
- UI dùng CSS thuần, template header/sidebar/footer, hỗ trợ sáng/tối. Có thể bổ sung menu và tái sử dụng các class form/card/table hiện hữu.

### Sổ phát sinh và khóa tồn

`StockLedger` là logic thuần, tính số dư từ tổng `stock_movements.qty`, khóa theo `warehouse_id:batch_id`. Không có bảng số tồn để cập nhật thủ công.

`StockLedger::movementsFor()` vẫn dựng cả hai đầu điều chuyển ngay một lần; được dùng cho validation/xem trước/test. Ghi sổ điều chuyển thực tế do `TransferService` thực hiện theo hai giai đoạn. Comment “cùng giao dịch” của helper không mô tả đúng workflow hiện tại.

`InventoryGuard::lockWarehouses()` lấy `pg_advisory_xact_lock` theo ID kho, sắp xếp ID để thống nhất thứ tự khóa. Đây là khóa dùng chung cho các service ghi tồn, không phải khóa dòng số dư kho/lô. Khóa này còn bảo vệ được trường hợp kho/lô chưa có phát sinh. Phải giữ cùng giao thức khóa cho service phân phối mới; chỉ thêm `FOR UPDATE` lên những dòng phát sinh đã tồn tại là chưa đủ.

### Phiếu nhập/xuất

1. `validateVoucher()` kiểm role `staff`, quyền kho nguồn đang hoạt động, lô/mặt hàng hoạt động, số nguyên dương, lô không trùng; kiểm tồn xem trước đối với xuất/điều chuyển.
2. `VoucherService::createDraft()` lưu `vouchers` và `voucher_lines` trong transaction. `request_key` unique chống tạo trùng.
3. `confirm()` khóa phiếu `FOR UPDATE`, kiểm quyền duyệt, lấy khóa kho, đọc tổng phát sinh hiện tại, chặn thiếu tồn khi xuất, ghi sổ và xác nhận trong cùng transaction.
4. `reject()` lưu người từ chối, thời điểm, lý do; không ghi tồn.

Không có nhà cung cấp, đơn giá, giá trị nhập hoặc chứng từ mua hàng. Không được suy diễn nguồn cung từ ghi chú cũ.

### Điều chuyển và gửi bù

- Dữ liệu gốc vẫn ở `vouchers`/`voucher_lines`; trạng thái chi tiết ở `transfer_workflow`.
- `draft → checked → in_transit → received/partial`. Kiểm hàng không khớp thì hủy; người duyệt có thể hủy khi `checked`.
- `check()`: role `counter`, quyền kho đi; lưu từng dòng đã soạn.
- `confirm()`: role `approver`, quyền cả hai kho; khóa phiếu/workflow/dòng, lấy khóa kho đi, kiểm tồn mới nhất, trừ đủ số gửi và chuyển `in_transit` trong transaction.
- `receive()`: role `staff`, quyền kho đến; khóa phiếu/workflow và kho đến, lưu số thực nhận/thiếu/lý do, cộng đúng số thực nhận trong transaction. Chặn nhận vượt số gửi.
- Gửi bù tạo phiếu điều chuyển con qua `vouchers.ref_id`. Khi tạo, khóa phiếu gốc, trừ số đã bù và số đang nằm trên phiếu bù chưa hoàn tất để tránh gửi bù vượt thiếu. Nhận đủ bù sẽ cập nhật trạng thái phiếu gốc.
- Hiện kho đi được chọn bất kỳ kho đến đang hoạt động, chưa có quan hệ cha–con hoặc giới hạn tuyến.
- Phần thiếu được lưu trong biên bản, chưa có sổ hàng đang đi hoặc quy trình xác định mất hàng/quyền sở hữu phần thiếu.

### Điều chỉnh, kiểm kê, báo cáo

- `AdjustmentService`: phiếu `adjust` và `adjustment_lines` tham chiếu `stock_movements` gốc; duyệt tạo phát sinh delta mới, không sửa dòng sổ gốc.
- `StockCountService`: khóa kho để chụp tồn; khi chốt, khóa đợt/dòng, đọc tồn mới nhất, tính lại delta và ghi chứng từ điều chỉnh/sổ/chốt đợt trong một transaction.
- Không lệch vẫn tạo phiếu điều chỉnh không có dòng để đáp ứng liên kết bắt buộc của `stock_counts`.
- `HistoryReport`: dùng chung cho lịch sử và CSV; tính tồn đầu/cuối kỳ và đối chiếu với kiểm kê đã chốt gần nhất. Các trang tồn/lịch sử chủ yếu scope theo kho được gán.

### Auth hiện tại

`users.role` chỉ nhận `admin`, `staff`, `approver`, `counter`; `user_warehouses` là phạm vi tường minh, không kế thừa phân cấp.

| Vai trò cũ | Quyền thực tế |
| --- | --- |
| admin | Quản lý danh mục và tài khoản; không tự động được lập/duyệt phiếu hoặc xem mọi kho |
| staff | Lập nhập/xuất/điều chuyển/điều chỉnh tại kho được gán; nhận điều chuyển tại kho đến |
| approver | Duyệt chứng từ theo phạm vi; điều chuyển cần cả hai kho; tạo/chốt kiểm kê |
| counter | Kiểm hàng điều chuyển tại kho đi; tạo/chốt kiểm kê trong phạm vi |

Backend đã có `requireLogin`, kiểm role/scope và CSRF. Tuy nhiên chưa có quyền tách riêng xem/tạo/duyệt/xuất/nhận/hủy, chưa có user điểm bán. Không thể chỉ bổ sung menu hoặc thêm role vào CHECK của database.

## 3. Database thực tế

Có 15 bảng: `warehouses`, `users`, `units`, `user_warehouses`, `items`, `batches`, `vouchers`, `voucher_lines`, `stock_movements`, `adjustment_lines`, `transfer_workflow`, `transfer_checks`, `transfer_receipt_lines`, `stock_counts`, `stock_count_lines`.

Các ràng buộc quan trọng:

- `warehouses.code` unique; chưa có loại kho hoặc `parent_id`.
- `(batches.id, item_id)` được tham chiếu bằng FK ghép ở các dòng hàng/sổ, tránh lô lệch mặt hàng.
- `vouchers.request_key` có unique index, cho phép NULL để tương thích chứng từ cũ.
- `stock_movements` bắt buộc có `voucher_id` và `warehouse_id`; unique `(voucher_id, warehouse_id, batch_id)`. Chưa thể ghi điểm bán hoặc phiếu giao hàng mới vào sổ mà không mở rộng mô hình này.
- Số lượng trong SQL là `numeric(14,3)`, còn nhiều service/helper/Store ép `(int)` và UI chỉ nhận số nguyên.
- Schema có FK/CHECK/index nhưng không có trigger ngăn UPDATE/DELETE sổ. Tính bất biến hiện phụ thuộc đường ghi của ứng dụng và quyền database.

Kết quả đọc database đang cấu hình:

| Nội dung | Kết quả |
| --- | --- |
| Kho / mặt hàng / dòng phát sinh | 3 / 30 / 223 |
| Đợt kiểm kê | 3; không có ID 2 |
| Nhóm kho/lô âm | 0 |
| Dòng phát sinh / dòng phiếu có phần thập phân | 0 / 0 |
| Điều chuyển thiếu bản ghi workflow | 0 |

Đây là các kiểm tra tổng hợp tại thời điểm audit, chưa phải chứng minh toàn bộ lịch sử đúng. Database có thêm phát sinh so với seed 203 dòng; không được coi là database trống hoặc tự reset.

## 4. Rủi ro và khoảng trống cần đưa vào kế hoạch

| Ưu tiên | Phát hiện từ source | Hướng xử lý |
| --- | --- | --- |
| Cao | `warehouses.php` lấy mọi kho, tính và hiển thị tổng tồn/số dòng/người được gán cả ở kho ngoài scope; class `is-locked` chỉ làm mờ | Scope dữ liệu trước tính/render, kiểm cả request GET và POST; test HTTP trực tiếp |
| Cao | `VoucherService::createDraft()` và `TransferService::createDraft()` phụ thuộc validation/quyền tại caller; service tạo chưa tự bảo vệ đầy đủ | Kiểm actor, dữ liệu và tuyến trong service/transaction; giữ validation form để hiển thị lỗi |
| Cao | `VoucherService::confirm()` không giới hạn rõ type `in/out`; nếu gọi nhầm với transfer/adjust có thể đi vào nhánh ghi tồn không đúng | Service từ chối sai loại; hiện trang `voucher.php` đang dispatch đúng service, chưa kết luận có đường khai thác HTTP này |
| Cao | `VoucherService::confirm/reject` và `AdjustmentService::confirm` có nhánh trả kết quả đã xử lý trước một số kiểm tra quyền | Xác thực actor/scope trước mọi nhánh retry; không để bypass khi thêm endpoint mới |
| Cao | `request_key` chưa được ràng buộc actor/payload; session tạo khóa nhưng chưa đối chiếu ở form thường | Retry chỉ trả chứng từ thuộc phạm vi, cùng actor/nghiệp vụ/payload; xung đột trả lỗi |
| Cao | Số lượng thập phân bị cắt khi ép int | Chốt số nguyên hay fixed-point 3 chữ số trước khi mở rộng; không dùng float cho số lượng chính xác |
| Cao | Test phụ thuộc database đang dùng và ID seed; chưa có test ghi sổ/service/concurrency/rollback | Tách fixture/database test cô lập; giữ nguyên kỳ vọng test, không sửa dữ liệu thật để làm test qua |
| Cao | Sổ hiện chỉ hỗ trợ kho và voucher cũ | Thiết kế nguồn chứng từ và địa điểm tồn rõ ràng trước phân phối; không giả điểm bán thành kho nội bộ |
| Vừa | Receive/check/cancel của transfer chặn retry bằng trạng thái, nhưng chưa trả lại kết quả thành công cũ; trang còn chặn retry trước service | Không ghi trùng hiện có; cần idempotency hoàn chỉnh, cùng payload trả kết quả cũ, payload khác bị từ chối |
| Vừa | Chưa có reservation tồn cho phiếu nháp/đã duyệt | Nếu chưa giữ hàng, phải ghi rõ duyệt không bảo đảm còn hàng; luôn kiểm lại dưới khóa lúc xuất |
| Vừa | Unique sổ theo phiếu/kho/lô không hỗ trợ nhiều lần giao trên cùng một voucher | Mỗi đợt giao là chứng từ nguồn độc lập; unique theo đợt/sự kiện/lô/địa điểm |
| Vừa | Hai adjustment lines tham chiếu hai movement cùng kho/lô có thể xung đột unique khi confirm | Tổng hợp delta theo kho/lô trước ghi sổ, vẫn giữ chi tiết tham chiếu để truy vết |
| Vừa | `Store` tải toàn bảng, lọc PHP và cache; quyền/active có thể là snapshot cũ trong request | Query có scope/phân trang; kiểm lại dữ liệu nhạy cảm trong transaction; tránh mở rộng cache toàn cục |
| Vừa | Bảo vệ admin cuối cùng trong `access.php` đếm nhưng chưa có khóa chống hai cập nhật đồng thời | Khóa phù hợp khi sửa tài khoản/quyền; kiểm thử race khi triển khai phân quyền |
| Vừa | `schema.sql` vừa khởi tạo vừa nâng cấp/backfill, không bọc toàn file bằng transaction và không bật `ON_ERROR_STOP` | Migration phiên bản riêng có preflight; không chạy lại schema một cách mù quáng trên dữ liệu thật |

Các phát hiện ở trên là audit source; trừ kết quả lint/test/truy vấn tổng hợp đã nêu, chưa có phép thử tái hiện HTTP hay kiểm thử race để xác nhận mức tác động thực tế.

## 5. Các thay đổi kiến trúc dự kiến

### File hiện có cần sửa ở giai đoạn triển khai

| File/nhóm file | Mục đích |
| --- | --- |
| `database/schema.sql` | Schema cài mới đồng nhất với phiên bản mở rộng, giữ tương thích cấu trúc cũ |
| `src/Auth.php`, `public/access.php` | Capability theo thao tác, scope kho/phân cấp/điểm bán; giữ role cũ qua mapping |
| `src/Store.php` | Normalize cột mới; query đọc có scope, không tải toàn bộ dữ liệu phân phối |
| `src/Validation.php`, `src/Input.php` | Validation nguồn cung, tuyến kho, điểm bán, số lượng và định dạng hiển thị |
| `src/VoucherService.php` | Supplier cho phiếu nhập mới; giới hạn nhập nguồn cung tại CENTRAL; tăng bảo vệ service/idempotency |
| `src/TransferService.php` | Kiểm tuyến cha–con dưới transaction, giữ check/dispatch/receive/gửi bù cũ; retry an toàn |
| `src/StockLedger.php`, `src/InventoryGuard.php` | Nguyên tắc cộng sổ dùng chung cho địa điểm mới, khóa nhất quán, tương thích chữ ký hàm cũ |
| `src/AdjustmentService.php`, `src/StockCountService.php` | Đồng bộ scope/capability và quy ước số lượng/khóa; tránh bỏ sót đường ghi sổ |
| `src/HistoryReport.php` | Tổng hợp theo loại địa điểm, chứng từ nguồn, nguồn cung, phân phối và quyền sở hữu |
| `src/bootstrap.php` | Nạp service/nhãn mới, giữ helper CSRF/POST/session |
| `public/warehouses.php` | Loại kho, cha–con, ngăn thay đổi quan hệ không hợp lệ, khắc phục lộ tổng tồn |
| `public/voucher-new.php`, `voucher.php`, `vouchers.php`, `transfer-supplement.php` | Supplier, loại kho, tuyến, thông tin lịch sử và capability mới |
| `public/index.php`, `stock.php`, `history.php`, `export.php` | Scope mới, bộ lọc và báo cáo; giữ URL cũ |
| `public/count.php`, `counts.php`, `adjust-new.php`, `templates/header.php` | Thay kiểm role cứng bằng capability phù hợp, menu có quyền |
| `tests/run.php`, `tests/helper.php`, `app.sh`, `README.md` | Test cô lập/tích hợp, hướng dẫn migration và sử dụng; sửa mô tả lệch thực tế |

Không cần đổi tên/xóa file hay thay toàn bộ CSS. `Database.php` chỉ cần thay nếu thiết kế kết nối test cô lập yêu cầu, không thay cấu hình database hiện tại.

Service dự kiến bổ sung: `SupplierService`, `WarehouseService`, `SalesPointService`, `SalesOrderService`, `SalesDeliveryService`, cùng lớp query báo cáo phân phối. Quy tắc tính số dư vẫn thuộc StockLedger; service điều phối chứng từ/transaction, không tự duy trì số tồn riêng.

### Bảng cần tạo/sửa — đề xuất để thiết kế tiếp

| Bảng | Thay đổi dự kiến |
| --- | --- |
| `suppliers` mới | Mã unique, tên công ty, mã số thuế, liên hệ, điện thoại, email, địa chỉ, active, ghi chú, timestamps |
| `warehouses` | Thêm `type` CENTRAL/REGIONAL và FK `parent_id`; CENTRAL không cha, REGIONAL có cha CENTRAL khi hoàn tất chuyển đổi |
| `vouchers` | Thêm FK `supplier_id` nullable cho dữ liệu cũ; phiếu nhập nguồn cung mới phải có supplier; không tạo bảng nhập trùng chức năng |
| `users`, `user_warehouses` | Giữ tài khoản và scope cũ; mở rộng mapping role/capability, không tự tăng quyền tài khoản cũ |
| `user_sales_points` mới | Scope tài khoản điểm bán; không cấp ngầm quyền kho cho user điểm bán |
| Bảng role/capability nếu cần | Chỉ thêm nếu chốt yêu cầu cấu hình quyền động; với role cố định có thể dùng mapping trong Auth, tránh overengineering |
| `sales_points` mới | Mã unique, loại, địa chỉ/liên hệ, kho chính REGIONAL, active, created_at, ghi chú, cấu hình theo dõi tồn theo thiết kế được chốt |
| `sales_point_warehouses` mới | Các kho thay thế được phép phục vụ điểm bán; không lặp thông tin kho chính; có kiểm quyền thiết lập liên kết |
| `sales_orders`, `sales_order_lines` mới | Chứng từ yêu cầu/duyệt phân phối, item/lô/qty, trạng thái, actor/timestamps, idempotency |
| `sales_deliveries`, `sales_delivery_lines` mới | Nhiều đợt giao/đơn, số xuất/thực nhận/chênh lệch, actor/timestamps/lý do; không dùng transfer_workflow |
| `stock_movements` | Mở rộng nguồn chứng từ giao/nhận và địa điểm điểm bán, giữ cột/quan hệ cho voucher cũ; bổ sung CHECK/FK/unique cho nguồn và sự kiện mới |

Đề xuất tiếp tục dùng một `stock_movements`, không tạo bảng số dư hoặc sổ điểm bán tính riêng. Dòng mới phải có đúng một địa điểm (kho hoặc điểm bán), đúng một nguồn chứng từ hợp lệ; dữ liệu cũ giữ `voucher_id`/`warehouse_id`. Thiết kế chi tiết phải rà lại NULL/FK/index và mọi query cũ trước khi chọn cấu trúc cuối. Không dùng ID đa hình thiếu FK và không nới bỏ toàn bộ ràng buộc nguồn chứng từ.

Giá nhập hiện chưa được hỗ trợ; không tự thêm kế toán/thuế vào phạm vi. Nếu cần giá, phải thiết kế tiền tệ, độ chính xác và quyền xem riêng.

### Luồng bổ sung và kế hoạch chuyển đổi

1. Hoàn tất thiết kế ERD, trạng thái, ma trận quyền và chốt các quyết định mục 6.
2. Tạo baseline test cô lập; tái hiện các vấn đề tồn/permission cần sửa và test hồi quy workflow hiện tại.
3. Nhà cung cấp: danh sách/chi tiết/tạo/sửa/tìm/lịch sử nhập; FK không cascade chứng từ; đã có chứng từ thì chỉ ngừng hoạt động.
4. Phân cấp kho: thêm cột theo kiểu expand, tạm cho phép kho cũ chưa phân loại; không tự chọn một trong ba kho làm CENTRAL. Preflight liệt kê kho, chứng từ mở và tuyến đang xử lý.
5. Tạo mới CENTRAL rỗng hoặc sử dụng CENTRAL do người nghiệp vụ chỉ định; chỉ gán ba kho cũ làm REGIONAL và parent sau khi có mapping được xác nhận. Không chuyển số tồn khi đổi quan hệ quản lý; nếu chuyển hàng vật lý phải lập chứng từ.
6. Trong thời gian chuyển đổi, giữ luồng lịch sử/điều chuyển cũ bằng chính sách legacy rõ ràng; khóa tạo tuyến mới chưa hợp lệ sau cutover. Không chặn tùy tiện phiếu cũ đang đi, không cho tuyến mới lách bằng cờ legacy.
7. Điểm bán và scope/capability; sau đó nối supplier vào phiếu nhập, kiểm loại kho và tuyến điều chuyển trong service lẫn form.
8. Đơn phân phối dùng service/workflow riêng: DRAFT → PENDING → APPROVED → DELIVERING → PARTIALLY_RECEIVED/COMPLETED. CANCELLED chỉ trước xuất. Phiếu đã xuất phải xử lý bằng nhận/hoàn/điều chỉnh có chứng từ, không xóa/đổi trạng thái để tự hoàn tồn.
9. Kiểm tồn lại khi xuất dưới khóa chung; lưu chứng từ, movement và trạng thái trong cùng transaction. Mỗi đợt giao có khóa idempotency riêng; retry không tạo thêm movement.
10. Bật tồn điểm bán chỉ theo mô hình sở hữu được chốt; mọi biến động tồn có chứng từ, không có form sửa số dư.
11. Báo cáo nguồn cung/tuyến/địa điểm/khu vực/chênh lệch từ chứng từ và sổ, phân biệt tồn vật lý/quyền sở hữu/hàng đang đi.

Migration cần: backup và diễn tập restore; preflight chỉ đọc, phát hiện orphan/duplicate/phân số/tồn âm/phiếu mở/tuyến cũ; migration phiên bản chạy với dừng khi lỗi và transaction; kiểm số dòng/tổng tồn từng kho/lô trước–sau; chạy lại không nhân đôi backfill; siết NOT NULL/CHECK sau mapping. Quan hệ hai tầng phải được bảo vệ cả khi đổi type/parent của kho cha và khi có cập nhật đồng thời. Rollback sau phát sinh mới ưu tiên forward-fix; không xóa sổ để quay schema về cũ.

## 6. Quyết định nghiệp vụ còn thiếu

| Quyết định | Đề xuất để xác nhận, chưa áp dụng |
| --- | --- |
| Kho tổng thật và mapping kho hiện tại | Tạo CENTRAL mới rỗng nếu chưa có; cần mã/tên/địa chỉ và xác nhận ba kho hiện tại thuộc CENTRAL nào |
| Tuyến nội bộ được phép | Mặc định mới chỉ CENTRAL → REGIONAL trực thuộc; cần chốt REGIONAL → CENTRAL, hai REGIONAL cùng cha và giữa các CENTRAL có được phép không |
| Quyền sở hữu tại điểm bán | Phân biệt COMPANY_OWNED, SOLD, CONSIGNMENT; phải khai báo mô hình trước giao, không tự mặc định ký gửi hay cộng tồn đối tác vào tài sản công ty |
| Quản lý tồn điểm bán đến đâu | Muốn biết tồn hiện tại phải có cả phát sinh ra: bán lẻ/tiêu hao/hoàn/kiểm kê; chỉ cộng hàng nhận sẽ chỉ biết lượng đã cấp ròng, không biết tồn thực |
| Thời điểm trừ kho phân phối | Đề xuất trừ đủ số đã giao khi xuất; cộng điểm bán theo thực nhận. Nếu chỉ trừ lúc nhận như thứ tự mô tả ban đầu thì phải có giữ hàng bảo vệ lượng đang đi |
| Thiếu/thừa và đóng đơn | Cần chốt thiếu tiếp tục chờ bù hay được đóng có phê duyệt; nhận thừa cần chứng từ bổ sung/điều chỉnh, không tự tăng vượt số xuất |
| Số lượng nguyên hay thập phân | Hiện UI/service chỉ hỗ trợ nguyên, SQL hỗ trợ 3 số lẻ. Có mặt hàng kg; cần chốt trước sửa quy ước toàn hệ thống |
| Duyệt và kho thay thế | Chốt ai được duyệt/xuất/nhận/hủy, có cấm tự duyệt, có giữ hàng khi APPROVED, kho thay thế có bắt buộc cùng CENTRAL không |
| Yêu cầu đặt hàng từ điểm bán | Đề xuất điểm bán chọn mặt hàng/số lượng, kho phân bổ lô khi duyệt; cần xác nhận nếu điểm bán phải chọn lô ngay lúc đặt |

Không biến các quyết định này thành giá trị mặc định âm thầm trong migration.

## 7. Kiểm thử và tiêu chí bàn giao tiếp theo

Kết quả đã chạy:

- `bash app.sh check`: `MOI_TRUONG=SAN_SANG`.
- `bash app.sh lint`: `LINT=PASS`.
- `bash app.sh test`: ngoài sandbox kết nối được PostgreSQL; 16 dòng PASS rồi exit 255 tại `tests/run.php:53` vì `Store::find('stock_counts', 2)` trả NULL. Chưa chạy tới `finish()`, không có kết quả 19/19. Lần chạy trong sandbox lỗi kết nối, không phải kết quả test nghiệp vụ.
- Ngay cả seed hiện tại cũng chỉ tạo `stock_counts.id=1`; vì vậy fixture ID 2 chưa được bảo đảm bởi script seed. Cần sửa cách dựng fixture cô lập, không sửa kỳ vọng để test qua.
- Test mang nhãn “logic thuần” nhưng gọi Store/PostgreSQL. Các test tồn đồng thời thực chất mô phỏng tuần tự; chưa kiểm transaction/service/HTTP.

Kế hoạch kiểm thử sau thiết kế:

- Test chức năng supplier, hierarchy, điểm bán, quyền backend; GET/POST đổi ID ngoài scope, CSRF sai/thiếu, phương thức không hợp lệ, retry khác actor/payload.
- PostgreSQL test cô lập: schema mới, nâng cấp dữ liệu cũ, phiếu đang xử lý, chạy lại migration, dữ liệu không hợp lệ bị preflight chặn, tổng tồn không đổi sau migration.
- Luồng 1.000 nhập → gửi 300 → nhận 290 → giao 100 → nhận 95. Theo đề xuất trừ khi xuất: CENTRAL 700, REGIONAL 190, điểm bán nhận 95 nếu bật tồn; thiếu nội bộ 10 và thiếu phân phối 5 được truy vết riêng. 95 có được tính vào tồn sở hữu công ty hay không phụ thuộc quyết định sở hữu.
- Hai kết nối PostgreSQL thực sự đồng thời, tồn 5 cùng xuất 4: chỉ một giao dịch thành công; kiểm cả xuất thường/transfer/phân phối dùng khóa chung.
- Gây lỗi sau dòng phát sinh đầu tiên để chứng minh rollback toàn bộ chứng từ/trạng thái/sổ của giai đoạn tương ứng.
- Retry duyệt/xuất/nhận không nhân đôi; hủy trước xuất không ảnh hưởng tồn; xử lý thiếu/gửi bù/nhận bằng 0/nhận thừa; kiểm thử mọi đường ghi sổ cũ.
- Báo cáo không đếm hai lần ở hai đầu giao nhận, không cộng hàng SOLD vào tài sản công ty; các bộ lọc tuân scope cả HTML và CSV.

Chưa có migration mới, ERD cuối, trang/API mới hoặc chức năng mới để tuyên bố hoàn thành. Giai đoạn 2 sẽ chốt ERD, ma trận quyền, trạng thái và đặc tả transaction dựa trên các quyết định trên; triển khai đúng thứ tự đã yêu cầu sau đó. Không tạo PR ở giai đoạn audit.
