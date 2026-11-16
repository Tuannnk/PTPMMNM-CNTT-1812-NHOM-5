<footer class="site-footer">Kho đa điểm · Quản lý danh mục, chứng từ và kiểm kê theo phạm vi kho được phân quyền.</footer>
</main>
</div>
<script>
document.addEventListener('click', event => {
  const toggle = event.target.closest('[data-password-toggle]');
  if (!toggle) return;
  const input = toggle.closest('.password-input-wrap')?.querySelector('input');
  if (!input) return;
  const reveal = input.type === 'password';
  const cursor = input.value.length;
  input.type = reveal ? 'text' : 'password';
  toggle.setAttribute('aria-pressed', String(reveal));
  toggle.setAttribute('aria-label', reveal ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
  input.focus();
  input.setSelectionRange(cursor, cursor);
});
</script>
</body>
</html>
