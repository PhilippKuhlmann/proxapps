#!/usr/bin/env bash
# Tests the release-line selection of catalog/authentik/update.sh (one line at a time).
set -euo pipefail
cd "$(dirname "$0")/../.."
eval "$(sed -n '/^# >>> release lines/,/^# <<< release lines/p' catalog/authentik/update.sh)"
declare -F release_lines next_line >/dev/null || { echo "FAIL Funktionen nicht in update.sh gefunden"; exit 1; }

tags='version/2026.8.6
version/2026.8.0-rc7
version/2026.5.8
version/2026.8.0
version/2026.2.7
version/2026.5.7
version/2025.12.4
version/2026.11.0-rc1
v0.1.0'

fail=0
check() {
  if [[ "$2" == "$3" ]]; then
    echo "ok   $1"
  else
    echo "FAIL $1: erwartet '$3', erhalten '$2'"
    fail=1
  fi
}

lines="$(release_lines <<<"$tags")"
check 'release lines, sorted, no release candidates' "$(tr '\n' ' ' <<<"$lines")" '2025.12 2026.2 2026.5 2026.8 '
check '2026.2 -> 2026.5 (not the newest)' "$(next_line 2026.2 <<<"$lines")" '2026.5'
check '2025.12 -> 2026.2 (across the year)' "$(next_line 2025.12 <<<"$lines")" '2026.2'
check '2026.5 -> 2026.8' "$(next_line 2026.5 <<<"$lines")" '2026.8'
check 'newest line stays' "$(next_line 2026.8 <<<"$lines")" ''
check 'unknown older line -> first newer one' "$(next_line 2025.10 <<<"$lines")" '2025.12'
check 'empty list' "$(next_line 2026.8 <<<'')" ''
exit "$fail"
