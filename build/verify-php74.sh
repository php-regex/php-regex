#!/usr/bin/env bash
#
# Verification gates for the generated twin — must run UNDER PHP 7.4 (the
# runner sets that up; locally, docker php:7.4-cli). Takes the reference
# digest produced by the 8.x side as $1 and fails on any divergence beyond
# the documented allowlist.
set -euo pipefail
cd "$(dirname "$0")/.."

fails=0
while IFS= read -r file; do
	if ! php -l "$file" > /dev/null 2>&1; then
		fails=$((fails + 1))
		php -l "$file" 2>&1 | head -1 || true
	fi
done < <(find build/php74 -name '*.php')

echo "lint gate: $fails failures"
[ "$fails" -eq 0 ]

php -d display_errors=stderr build/smoke/digest.php \
	build/smoke/vendor/autoload.php > /tmp/twin-digest.jsonl

php build/compare-digests.php "$1" /tmp/twin-digest.jsonl
