<?php

declare(strict_types=1);

/**
 * Restores the parameter types Rector writes wrong on PHP 7.4. Downgrading a
 * promoted nullable parameter typed with an enum, Rector writes another enum
 * of the namespace, and without the leading backslash: `?GroupType $assertion`
 * comes out as `?PHPRegex\Parser\ErrorCode $assertion`, a class that does not
 * exist in the file's namespace, and the constructor throws a TypeError.
 *
 * A relative `PHPRegex\...` type is never right in a namespaced file, so each
 * one is replaced by the type the original source declares for the same
 * parameter. A type the original does not declare stops the build.
 */
require __DIR__ . '/../vendor/autoload.php';

$root = __DIR__ . '/php74/Parser';
$original = __DIR__ . '/../src/Parser';

$restored = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}

	$path = $file->getPathname();
	$source = file_get_contents($path);
	$relative = substr($path, strlen($root));

	$new = preg_replace_callback(
		'/(?<![\\\\\w])(\??)PHPRegex\\\\[\w\\\\]+(\s+\$(\w+))/',
		static function (array $m) use ($original, $relative, $path, &$restored): string {
			$originalSource = (string) file_get_contents($original . $relative);
			if (!preg_match('/(\??)(\\\\?[A-Z]\w*(?:\\\\\w+)*)\s+\$' . preg_quote($m[3], '/') . '\b/', $originalSource, $declared)) {
				fwrite(STDERR, sprintf("restore-enum-types: no declared type for \$%s in %s\n", $m[3], $path));
				exit(1);
			}

			$restored++;

			return $declared[1] . '\\' . resolve($declared[2], $originalSource) . $m[2];
		},
		$source,
	);

	if ($new !== null && $new !== $source) {
		file_put_contents($path, $new);
	}
}

echo "relative enum types restored: $restored\n";

/**
 * The fully qualified name of a type written in the original file.
 */
function resolve(string $type, string $source): string
{
	if ($type[0] === '\\') {
		return substr($type, 1);
	}

	$first = explode('\\', $type)[0];
	if (preg_match('/^use\s+([\w\\\\]+\\\\' . preg_quote($first, '/') . ');/m', $source, $use)) {
		return $use[1] . substr($type, strlen($first));
	}

	preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $namespace);

	return $namespace[1] . '\\' . $type;
}
