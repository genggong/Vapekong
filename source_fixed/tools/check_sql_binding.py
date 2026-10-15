#!/usr/bin/env python3
"""Static SQL binding checker.

Mencari PDO prepare() dengan jumlah placeholder '?' yang TIDAK sama dengan
jumlah elemen array execute([...]).两类 bug:
  - too few values  -> PHP fatal / wrong data silently shifted
  - too many values -> Array to string / notice

Dijalankan tanpa database: parse source PHP jadi AST-ish per statement.
"""
import re
import sys
from pathlib import Path

ROOT = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
PHP = sorted(ROOT.rglob("*.php"))

# $var = $db->prepare("SQL") ;  ... $var->execute([...])   (sama atau tidak)
PREPARE = re.compile(
    r"(?P<var>\$\w+)\s*=\s*\$\w+->prepare\(\s*(?P<q>['\"])(?P<sql>(?:\\.|(?!(?P=q)).)*)(?P=q)\s*\)",
    re.S,
)
EXECUTE = re.compile(
    r"(?P<var>\$\w+)->execute\(\s*(?P<q2>['\"]|\[)(?P<body>(?:\\.|[^;])*?)\)\s*;",
    re.S,
)


def count_placeholders(sql: str) -> int:
    """Hitung ? di luar string literal dan tanpa escaped \\?."""
    n, i, quote, in_sql_comment = 0, 0, None, False
    while i < len(sql):
        ch = sql[i]
        if in_sql_comment:
            if ch == "\n":
                in_sql_comment = False
        elif quote:
            if ch == "\\":
                i += 2
                continue
            if ch == quote:
                quote = None
        else:
            if ch in "'\"":
                quote = ch
            elif ch == "-" and sql[i : i + 2] == "--":
                in_sql_comment = True
            elif ch == "?":
                n += 1
        i += 1
    return n


def count_array_elements(body: str) -> int:
    """Top-level element count sebuah array literal PHP."""
    body = body.strip()
    if not body.startswith("["):
        return -1  # bukan array literal (mis. variabel)
    inner = body[1:]
    depth, quote, n, saw, i = 0, None, 0, False, 0
    while i < len(inner):
        ch = inner[i]
        if quote:
            if ch == "\\":
                i += 2
                continue
            if ch == quote:
                quote = None
        else:
            if ch in "'\"":
                quote = ch
            elif ch in "([{":
                depth += 1
            elif ch in ")]}":
                depth -= 1
            elif ch == "," and depth == 0:
                n += 1
                saw = True
            elif ch == "[" and depth == 0:
                saw = True  # elemen pertama adalah array
        i += 1
    text = inner.strip()
    return (n + 1) if (saw or text) else 0


def main() -> int:
    findings = []
    for path in PHP:
        try:
            src = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        preps = {}
        for m in PREPARE.finditer(src):
            preps[m.group("var")] = (count_placeholders(m.group("sql")), m.group("sql"), m.start())
        if not preps:
            continue
        for m in EXECUTE.finditer(src):
            var = m.group("var")
            if var not in preps:
                continue
            want, sql, prep_at = preps[var]
            body = m.group("body").strip()
            if not body.startswith("["):
                continue  # execute($vars) -> tidak bisa dihitung statis
            got = count_array_elements(body)
            if got != want:
                line = src[: m.start()].count("\n") + 1
                findings.append((path, line, want, got, " ".join(sql.split())[:90]))

    if not findings:
        print(f"OK: {len(PHP)} file PHP diperiksa, tidak ada mismatch placeholder.")
        return 0

    print(f"{len(findings)} MISMATCH placeholder vs execute():\n")
    for path, line, want, got, sql in findings:
        rel = path.relative_to(ROOT)
        print(f"  {rel}:{line}")
        print(f"    placeholder={want}  nilai={got}  diff={got - want:+d}")
        print(f"    SQL: {sql}\n")
    return 1


if __name__ == "__main__":
    sys.exit(main())
