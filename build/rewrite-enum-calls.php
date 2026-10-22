<?php

declare(strict_types=1);

/**
 * Rewrites `Enum::Case` into `Enum::Case()` across the downgraded tree, the
 * one syntactic change the cached-instance twins need. `Enum::class`,
 * `Enum::from()`, `Enum::cases()` and friends keep their spelling: the case
 * pattern starts with an uppercase letter, the helpers do not.
 */

$root = __DIR__ . '/php74/Parser';

$enums = 'ErrorCode|TraversalAction|BsrConvention|NewlineConvention|PcreFeature|TokenType|LiteralConfidence|Participation|AssertionKind|Greed|LookKind|FormatRefusal|QuantifierType|ClassSetOperator|GroupType|CharLiteralType|ValidationErrorCategory';

$rewrites = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}

	$path = $file->getPathname();
	$source = file_get_contents($path);
	$source = preg_replace(
		'/\b(' . $enums . ')::([A-Z][A-Za-z0-9_]*)\b(?!\s*\()/',
		'$1::$2()',
		$source,
		-1,
		$count,
	);
	$rewrites += (int) $count;

	if ($count > 0) {
		file_put_contents($path, $source);
	}
}

echo "Enum::Case -> Enum::Case() rewrites: $rewrites\n";
