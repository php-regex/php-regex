<?php

declare(strict_types=1);

/**
 * Hoists class constants that now call enum factories into lazy static
 * methods of the same name: PHP only allows constant expressions in const
 * declarations, and Enum::Case() is a call. Usages self::NAME become
 * self::NAME().
 */

$root = __DIR__ . '/php74/Parser';

$convertedPerFile = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}

	$path = $file->getPathname();
	$source = file_get_contents($path);

	$new = preg_replace_callback(
		'/(?:private|protected|public)\s+const\s+(\w+)\s*=\s*(\[[^;]*?\]);/s',
		function (array $m) use (&$convertedPerFile, $path) {
			if (!preg_match('/::[A-Z][A-Za-z0-9_]*\(\)/', $m[2])) {
				return $m[0];
			}

			$convertedPerFile[$path][$m[1]] = true;

			return "private static function {$m[1]}(): array\n    {\n"
				. "        static \$cache = null;\n"
				. "        if (\$cache === null) {\n"
				. "            \$cache = {$m[2]};\n"
				. "        }\n\n"
				. "        return \$cache;\n    }";
		},
		$source,
	);

	if ($new !== null && $new !== $source) {
		file_put_contents($path, $new);
	}
}

$usageRewrites = 0;
foreach ($convertedPerFile as $path => $names) {
	$alt = implode('|', array_keys($names));
	$source = file_get_contents($path);
	$new = preg_replace(
		'/\b(self|static)::(' . $alt . ')\b(?!\s*\()/',
		'$1::$2()',
		$source,
		-1,
		$count,
	);
	$usageRewrites += (int) $count;

	if ($count > 0) {
		file_put_contents($path, $new);
	}
}

$all = [];
foreach ($convertedPerFile as $names) {
	foreach (array_keys($names) as $name) {
		$all[$name] = true;
	}
}
echo 'consts hoisted (per file, rewritten only where hoisted): ', implode(', ', array_keys($all)), "\n";
echo "self::NAME -> self::NAME() rewrites: $usageRewrites\n";
