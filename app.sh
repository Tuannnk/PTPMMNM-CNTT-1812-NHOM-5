#!/usr/bin/env bash
# Chạy và kiểm thử dự án cục bộ. Không cài gói, không xóa dữ liệu.
set -u
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || exit 2
cd "$root" || exit 2
port="${PORT:-8110}"

usage() {
  printf 'Cách dùng: bash app.sh check | run | test | lint\n'
}
check_env() {
  command -v php >/dev/null 2>&1 || { echo 'THIEU_PHP: sudo apt install php-cli'; return 2; }
  php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo 'CAN_PHP_8_1_TRO_LEN'; return 2; }
  echo 'MOI_TRUONG=SAN_SANG'
}
case "${1:-help}" in
  check) check_env ;;
  run)
    check_env || exit 2
    mkdir -p .local/sessions || exit 2
    echo "DIA_CHI=http://127.0.0.1:$port"
    echo 'Giữ terminal này mở. Nhấn Ctrl+C để dừng.'
    php -d session.name=KHODADIEM -d session.save_path="$root/.local/sessions" -S "127.0.0.1:$port" -t public ;;
  test)
    check_env || exit 2
    php tests/run.php ;;
  lint)
    check_env || exit 2
    fail=0
    while IFS= read -r -d '' file; do
      php -l "$file" >/dev/null || { echo "LOI_CU_PHAP $file"; fail=1; }
    done < <(find src public templates data tests -name '*.php' -print0)
    [[ "$fail" -eq 0 ]] && echo 'LINT=PASS' || exit 1 ;;
  help|-h|--help) usage ;;
  *) usage; exit 2 ;;
esac
