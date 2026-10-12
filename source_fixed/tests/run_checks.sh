#!/usr/bin/env bash
# Suite verifikasi Vapekong POS: static SQL binding + regression test + lint.
# Semua run lokal, tidak butuh database produksi.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

fail=0
step() { printf '\n=== %s ===\n' "$1"; }

step "PHP lint"
lint_bad=0
while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null 2>&1 || { echo "SYNTAX ERROR: $f"; php -l "$f"; lint_bad=1; fail=1; }
done < <(find app legacy tools tests -name '*.php' -print0 2>/dev/null)
[ "$lint_bad" -eq 0 ] && echo "OK: semua file PHP valid"

step "JS syntax"
if command -v node >/dev/null 2>&1; then
    js_bad=0
    while IFS= read -r -d '' f; do
        node --check "$f" >/dev/null 2>&1 || { echo "SYNTAX ERROR: $f"; js_bad=1; fail=1; }
    done < <(find legacy/public/assets/js -name '*.js' -print0 2>/dev/null)
    [ "$js_bad" -eq 0 ] && echo "OK: semua file JS valid"
else
    echo "SKIP: node tidak tersedia"
fi

step "SQL placeholder binding"
python3 tools/check_sql_binding.py legacy || fail=1

step "Regression tests"
python3 -m unittest discover -s tests -p 'test_*.py' 2>&1 | tail -5 || fail=1

printf '\n========================================\n'
[ "$fail" -eq 0 ] && echo "SEMUA VERIFIKASI LULUS" || echo "ADA VERIFIKASI YANG GAGAL"
exit "$fail"
