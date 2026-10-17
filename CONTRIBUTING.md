# Hướng dẫn đóng góp

## Quy trình

1. Mở **issue** mô tả việc cần làm hoặc lỗi gặp phải (kèm bước tái hiện).
2. Tạo nhánh từ `main`: `feature/<mô-tả-ngắn>` hoặc `fix/<mô-tả-ngắn>`.
3. Commit nhỏ, thông điệp rõ ràng, ví dụ: `Thêm kiểm tra tồn âm khi xác nhận phiếu xuất`.
4. Trước khi mở Pull Request:
   ```bash
   bash app.sh lint
   bash app.sh test
   ```
5. Mở **Pull Request**, liên kết issue (`Closes #12`). Cần ít nhất **một thành viên khác review** trước khi merge.

## Quy ước mã

- Quy tắc nghiệp vụ đặt trong `src/`: không `echo`, không HTML, không đọc `$_POST`/`$_SESSION` trực tiếp.
- Trang trong `public/` chỉ nhận request, gọi xử lý và hiển thị.
- Mọi thao tác thay đổi dữ liệu dùng POST, có `csrfInput()` và chuyển hướng 303.
- Mọi giá trị in ra HTML đều qua `e()`.
- Quyền theo kho luôn được kiểm tra phía máy chủ.
- Sửa lỗi phải kèm ca kiểm thử tái hiện lỗi đó. Không sửa giá trị mong đợi trong test để cho test qua.

## Dữ liệu

Chỉ dùng dữ liệu giả. Không commit mật khẩu, tệp `.env` hoặc dữ liệu thật.
