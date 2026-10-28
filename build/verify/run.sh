#!/usr/bin/env bash
#
# Checks the generated twin against the original on the official PHP images,
# as the twin workflow does: for each PHP of the arguments, the original's
# readings are written on that PHP (on 8.1 for 7.4 and 8.0, which the
# original does not run on), then the twin's suite runs there against them.
#
#   bash build/verify/run.sh [7.4 8.0 8.1 8.2 8.3 8.4 8.5 8.6-rc]
#   bash build/verify/run.sh --local <php binary>
#
# Needs docker, a generated twin (build/generate.sh) and the twin's
# environment (composer install --working-dir build/smoke). --local needs no
# docker: the original's readings are written by the php on PATH, the
# twin's suite runs on the binary given, and both must link the same PCRE2,
# so that every reading is the same.
set -euo pipefail
cd "$(dirname "$0")/../.."

if [ "${1:-}" = "--local" ]; then
	twin_php=${2:?"Usage: bash build/verify/run.sh --local <php binary>"}
	pcre() { "$1" -r 'echo PCRE_VERSION;'; }
	if [ "$(pcre php)" != "$(pcre "$twin_php")" ]; then
		echo "php links PCRE2 $(pcre php), $twin_php $(pcre "$twin_php"): a local check needs one engine." >&2
		exit 2
	fi

	dir=.cache/twin-verify/reference-local
	rm -rf "$dir"
	mkdir -p "$dir"
	php build/verify/patterns.php > "$dir/patterns.json"
	for i in 0 1 2 3; do
		php -d memory_limit=2G build/verify/run-witness.php build/verify/original-autoload.php "$dir/patterns.json" "$i/4" --hash > "$dir/witnesses-$i.jsonl" &
	done
	wait
	php build/verify/api.php build/verify/original-autoload.php src/Parser > "$dir/api.json"

	echo "== twin on PHP $("$twin_php" -r 'echo PHP_VERSION;'), against the original on PHP $(php -r 'echo PHP_VERSION;'), PCRE2 $(pcre php)"
	TWIN_REFERENCE="$dir" TWIN_SAME_ENGINE=1 "$twin_php" -d memory_limit=2G build/smoke/vendor/bin/phpunit -c build/phpunit.xml.dist
	exit
fi

versions=("$@")
if [ ${#versions[@]} -eq 0 ]; then
	versions=(7.4 8.0 8.1 8.2 8.3 8.4 8.5 8.6-rc)
fi

out=.cache/twin-verify
mkdir -p "$out"
php build/verify/patterns.php > "$out/patterns.json"

# a reference is written again when the original, the readings or the
# patterns change
inputs=$( (find src/Parser build/verify -type f -name '*.php' | LC_ALL=C sort | xargs cat; cat "$out/patterns.json") | php -r 'echo md5(stream_get_contents(STDIN));')

reference() {
	local php=$1 dir="$out/reference-$1-$inputs"
	if [ -f "$dir/done" ]; then
		return
	fi

	rm -rf "$dir"
	mkdir -p "$dir"
	cp "$out/patterns.json" "$dir/"
	docker run --rm -v "$PWD":/repo -w /repo "$(image "$php")" sh -c '
		for i in 0 1 2 3; do
			php -d memory_limit=2G build/verify/run-witness.php build/verify/original-autoload.php "$0/patterns.json" "$i/4" --hash > "$0/witnesses-$i.jsonl" &
		done
		wait
		php build/verify/api.php build/verify/original-autoload.php src/Parser > "$0/api.json"
	' "$dir"
	touch "$dir/done"
}

# the 8.6-rc image ships without mbstring, which the original and PHPUnit
# read: an image with it is built once
image() {
	case "$1" in
		8.6*)
			if ! docker image inspect "twin-php:$1" > /dev/null 2>&1; then
				printf 'FROM php:%s-cli\nRUN apt-get update && apt-get install -y --no-install-recommends libonig-dev && docker-php-ext-install mbstring\n' "$1" \
					| docker build -q -t "twin-php:$1" - > /dev/null
			fi
			echo "twin-php:$1"
			;;
		*) echo "php:$1-cli" ;;
	esac
}

status=0
for php in "${versions[@]}"; do
	case "$php" in
		7.4|8.0) against=8.1 ;;
		*) against=$php ;;
	esac

	reference "$against"
	echo "== twin on PHP $php, against the original on PHP $against"
	if ! docker run --rm -v "$PWD":/repo -w /repo -e TWIN_REFERENCE="$out/reference-$against-$inputs" "$(image "$php")" \
		php -d memory_limit=2G build/smoke/vendor/bin/phpunit -c build/phpunit.xml.dist; then
		status=1
	fi
done

exit $status
