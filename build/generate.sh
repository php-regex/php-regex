#!/usr/bin/env bash
#
# Generates the PHP 7.4 twin of src/Parser under build/php74. Meant to run
# under PHP 8.x (the toolchain needs modern syntax); the verification gates
# run separately, under a real PHP 7.4.
set -euo pipefail
cd "$(dirname "$0")/.."

rm -rf build/php74
mkdir -p build/php74
cp -R src/Parser build/php74/Parser

tools/rector/vendor/bin/rector process build/php74/Parser \
	--config build/rector-downgrade.php --no-progress-bar --no-diffs

php build/emit-enum-factories.php > /dev/null
php build/rewrite-enum-calls.php
php build/hoist-enum-consts.php

# the two signatures Rector mangles, by hand
perl -i -pe 's/bool \$changed, \?\s*$/bool \$changed, ?FormatRefusal/' \
	build/php74/Parser/Printer/FormatResult.php
perl -i -pe 's/\?final \$category/?ValidationErrorCategory \$category/; s/\?final \$errorCode/?ErrorCode \$errorCode/' \
	build/php74/Parser/Validation/ValidationResult.php

# PHP 8.0 syntax Rector leaves behind: $var::class and 0o octal literals
grep -rl '\$[a-zA-Z_][a-zA-Z0-9_]*::class' build/php74 --include='*.php' \
	| while read -r file; do
		perl -i -pe 's/\$([a-zA-Z_][a-zA-Z0-9_]*)::class/get_class(\$$1)/g' "$file"
	done
grep -rl '0o[0-7]' build/php74 --include='*.php' \
	| while read -r file; do
		perl -i -pe 's/\b0o([0-7]+)\b/0$1/g' "$file"
	done

# style the shipped tree here, not as a gate: what gets verified is what
# gets published
if [ -x tools/php-cs-fixer/vendor/bin/php-cs-fixer ]; then
	tools/php-cs-fixer/vendor/bin/php-cs-fixer fix build/php74 \
		--path-mode=override --quiet || true
fi

# @readonly docblocks lie in the twin (properties are plain now); the @ is
# escaped so perl does not interpolate an array out of the pattern
find build/php74 -name '*.php' -exec perl -i -ne 'print unless /^\s*\* \@readonly\s*$/' {} +

# the twin is a package: the same composer manifest the mirror repository
# would publish, so consumers install it exactly as CI tested it
cat > build/php74/composer.json <<'MANIFEST'
{
	"name": "php-regex/regex-parser-php74",
	"description": "The PHPRegex parser built to run on hosts PHP 7.4-8.0. On PHP 8.1+, install php-regex/regex-parser instead.",
	"license": "MIT",
	"version": "2.x-dev",
	"type": "library",
	"require": {
		"php": "^7.4 || ^8.0",
		"symfony/polyfill-php80": "^1.31",
		"symfony/polyfill-php81": "^1.31",
		"symfony/polyfill-mbstring": "^1.31"
	},
	"autoload": {
		"psr-4": {
			"PHPRegex\\Parser\\": "Parser/"
		}
	},
	"config": {
		"allow-plugins": false
	}
}
MANIFEST

echo "twin generated: $(find build/php74 -name '*.php' | wc -l | tr -d ' ') files"
