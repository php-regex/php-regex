#!/usr/bin/env bash
#
# Generates the PHP 7.4 twin of src/Parser under build/php74. Meant to run
# under PHP 8.x (the toolchain needs modern syntax); the verification gates
# run separately, under a real PHP 7.4.
set -euo pipefail
cd "$(dirname "$0")/.."

# Rector drops what stands before a match expression it turns into a
# switch inside a binary operation ($a && match, 'x'.match) or a compound
# assignment ($s .= match): the source writes such a match to a variable
# first
if grep -rnE '(\.|&&|\|\||\?\?) ?match \(|(\.|\+|-|\*|/|\?\?|\|)= ?match \(' src/Parser --include='*.php'; then
	echo 'generate.sh: a match expression inside a binary operation or a compound assignment, which Rector downgrades wrong' >&2
	exit 1
fi

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

# the promoted nullable enum parameters Rector types with another enum of
# the namespace, written relative: restored from the original declarations
php build/restore-enum-types.php

# WeakMap is PHP 8.0: the analyses key their per-node caches with it, and
# SplObjectStorage gives the same array access on 7.4
grep -rl 'WeakMap' build/php74 --include='*.php' \
	| xargs -r -n1 perl -i -pe 's/\\WeakMap\b/\\SplObjectStorage/g' || true

# style the shipped tree here, not as a gate: what gets verified is what
# gets published. It runs BEFORE the floor fixes below, because the style
# fixers know modern PHP and would happily reintroduce the very syntax
# the twin exists to shed (get_class($x) straight back to $x::class).
if [ -x tools/php-cs-fixer/vendor/bin/php-cs-fixer ]; then
	tools/php-cs-fixer/vendor/bin/php-cs-fixer fix build/php74 \
		--path-mode=override --quiet || true
fi

# PHP 8.0 syntax Rector and the style pass leave behind: $var::class and
# 0o octal literals. A quiet grep is the success case — nothing left.
grep -rl '\$[a-zA-Z_][a-zA-Z0-9_]*::class' build/php74 --include='*.php' \
	| xargs -r -n1 perl -i -pe 's/\$([a-zA-Z_][a-zA-Z0-9_]*)::class/get_class(\$$1)/g' || true
grep -rl '0o[0-7]' build/php74 --include='*.php' \
	| xargs -r -n1 perl -i -pe 's/\b0o([0-7]+)\b/0$1/g' || true

# nothing PHP 7.4 cannot load is left: no WeakMap, no relative PHPRegex\
# type in a namespaced file
if grep -rnE 'WeakMap|[(,] *\??PHPRegex\\' build/php74 --include='*.php'; then
	echo 'generate.sh: a PHP 8.0 class or a relative type is left in the twin' >&2
	exit 1
fi

# @readonly docblocks lie in the twin (properties are plain now); the @ is
# escaped so perl does not interpolate an array out of the pattern
find build/php74 -name '*.php' -exec perl -i -ne 'print unless /^\s*\* \@readonly\s*$/' {} +

# the twin is a package laid out like any other: code under src/, the
# repo furniture the split carries (readme with its art, license,
# changelog, schema, git attributes and workflows, IDE meta) at the root
mv build/php74/Parser build/php74/src
for rootFile in .github .gitattributes .phpstorm.meta.php README.md ast.schema.json art LICENSE CHANGELOG.md; do
	if [ -e "build/php74/src/$rootFile" ]; then
		mv "build/php74/src/$rootFile" "build/php74/$rootFile"
	fi
done

# the split fragment's manifest goes with it; what it says that matters
# lands in the twin's own manifest below
rm -f build/php74/src/composer.json

# the same composer manifest the mirror repository publishes, so
# consumers install it exactly as CI tested it
cat > build/php74/composer.json <<'MANIFEST'
{
	"name": "php-regex/regex-parser-php74",
	"description": "The PHPRegex parser built to run on hosts PHP 7.4-8.0. On PHP 8.1+, install php-regex/regex-parser instead.",
	"license": "MIT",
	"type": "library",
	"keywords": [
		"regex",
		"pcre",
		"pcre2",
		"parser",
		"ast",
		"lexer",
		"validation"
	],
	"homepage": "https://github.com/php-regex/regex-parser-php74",
	"support": {
		"issues": "https://github.com/php-regex/regex-parser-php74/issues",
		"source": "https://github.com/php-regex/regex-parser-php74",
		"docs": "https://php-regex.com/reference/api/"
	},
	"funding": [
		{
			"type": "github",
			"url": "https://github.com/sponsors/yoeunes"
		}
	],
	"authors": [
		{
			"name": "Younes ENNAJI",
			"email": "younes.ennaji.pro@gmail.com",
			"homepage": "https://www.linkedin.com/in/yoeunes/",
			"role": "Developer"
		}
	],
	"require": {
		"php": "^7.4 || ^8.0",
		"symfony/polyfill-php80": "^1.31",
		"symfony/polyfill-php81": "^1.31",
		"symfony/polyfill-mbstring": "^1.31"
	},
	"suggest": {
		"ext-intl": "To resolve \\N{name} escapes by Unicode name.",
		"psr/cache": "To share the AST cache through a PSR-6 pool.",
		"psr/simple-cache": "To share the AST cache through a PSR-16 cache."
	},
	"autoload": {
		"psr-4": {
			"PHPRegex\\Parser\\": "src/"
		}
	},
	"extra": {
		"branch-alias": {
			"dev-2.x": "2.x-dev"
		}
	},
	"config": {
		"allow-plugins": false
	}
}
MANIFEST

echo "twin generated: $(find build/php74/src -name '*.php' | wc -l | tr -d ' ') files under src/, $(ls build/php74 | grep -v src | wc -l | tr -d ' ') root entries"
