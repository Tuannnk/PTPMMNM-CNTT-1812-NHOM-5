-- Tài khoản giả lập: đặt mật khẩu mẫu cho những tài khoản chưa có mật khẩu.
-- Không thay đổi mật khẩu đã được thiết lập trước đó.
UPDATE users
SET password_hash = '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO'
WHERE username IN ('nv.hn', 'nv.dn', 'duyet', 'kiemke')
  AND password_hash IS NULL;
