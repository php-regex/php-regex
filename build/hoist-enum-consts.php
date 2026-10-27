<?php

declare(strict_types=1);

/**
 * Hoists class constants that now call enum factories into static methods
 * of the same name, each reading a static property it fills once: PHP only
 * allows constant expressions in const declarations, and Enum::Case() is a
 * call. Usages self::NAME become self::NAME(). The property and the method
 * carry the type the constant's literal gives.
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
		'~(?:(/\*\*(?:(?!\*/).)*\*/)(\s*))?(private|protected|public)\s+const\s+(\w+)\s*=\s*(\[[^;]*?\]);~s',
		static function (array $m) use (&$convertedPerFile, $path): string {
			[, $doc, $space, $visibility, $name, $literal] = $m;
			if (!preg_match('/::[A-Z][A-Za-z0-9_]*\(\)/', $literal)) {
				return $m[0];
			}

			// a method is private where it is hoisted: a constant another
			// class reads would leave that class reading a constant gone
			if ($visibility !== 'private') {
				fwrite(STDERR, "hoist-enum-consts: $name in $path is $visibility; only a private constant can become a method\n");
				exit(1);
			}

			$convertedPerFile[$path][$name] = true;
			$type = literalType($literal, $name, $path);

			$doc = $doc === ''
				? "/**\n     * @var {$type}|null\n     */"
				: rtrim(substr($doc, 0, -2)) . "\n     *\n     * @var {$type}|null\n     */";

			return $doc . ($space === '' ? "\n    " : $space)
				. "private static ?array \${$name} = null;\n\n"
				. "    /**\n     * @return {$type}\n     */\n"
				. "    private static function {$name}(): array\n    {\n"
				. "        return self::\${$name} ??= {$literal};\n    }";
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

/**
 * The PHPDoc type of a constant array the generator hoists: a list or a
 * string-keyed map of enum cases. Any other element stops the build, so a
 * constant of another shape is typed by hand, not guessed.
 */
function literalType(string $literal, string $name, string $path): string
{
	$body = trim(substr($literal, 1, -1));
	$items = array_filter(array_map('trim', preg_split('/,(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $body)), static fn (string $item): bool => $item !== '');

	$classes = [];
	$keyed = null;
	foreach ($items as $item) {
		if (!preg_match('/^(?:(\'[^\']*\'|"[^"]*")\s*=>\s*)?(\w+)::[A-Z][A-Za-z0-9_]*\(\)$/', $item, $parts)) {
			fwrite(STDERR, "hoist-enum-consts: $name in $path holds '$item', which is no enum case\n");
			exit(1);
		}

		$isKeyed = $parts[1] !== '';
		if ($keyed !== null && $keyed !== $isKeyed) {
			fwrite(STDERR, "hoist-enum-consts: $name in $path mixes keyed and unkeyed elements\n");
			exit(1);
		}

		$keyed = $isKeyed;
		$classes[$parts[2]] = true;
	}

	if ($classes === []) {
		fwrite(STDERR, "hoist-enum-consts: $name in $path is empty\n");
		exit(1);
	}

	$value = implode('|', array_keys($classes));

	return $keyed ? "array<string, {$value}>" : "list<{$value}>";
}
