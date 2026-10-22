<?php

declare(strict_types=1);

/**
 * Rewrites the enum usages the Rector downgrade leaves behind: enum classes
 * became plain string-constant classes, so `->enumTypedProp->value` no
 * longer has an object to read. Real string properties (like
 * LiteralNode::$value) are left alone: only properties documented against
 * one of the downgraded enums are rewritten, and the four classes whose
 * ::from/::tryFrom/::cases are still called get a reflection-based shim.
 */

$root = __DIR__ . '/out/Parser';

$enums = [
	'ErrorCode', 'TraversalAction', 'BsrConvention', 'NewlineConvention',
	'PcreFeature', 'TokenType', 'LiteralConfidence', 'Participation',
	'AssertionKind', 'Greed', 'LookKind', 'FormatRefusal', 'QuantifierType',
	'ClassSetOperator', 'GroupType', 'CharLiteralType', 'ValidationErrorCategory',
];

$alt = implode('|', $enums);

$typedNames = [];
$propRewrites = 0;

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}

	$path = $file->getPathname();
	$source = file_get_contents($path);

	// a docblock followed by a property declaration: when the docblock
	// carries an @var naming an enum, the property holds a plain string now
	$source = preg_replace_callback(
		'~/\*\*(.*?)\*/(\s*)(public|protected|private)\s+\$(\w+)~s',
		function (array $m) use ($alt, &$typedNames) {
			if (preg_match('/@var\s+[\\\\?A-Za-z]*\b(' . $alt . ')\b/', $m[1])) {
				$typedNames[$m[4]] = true;
			}

			return $m[0];
		},
		$source,
	);

	file_put_contents($path, $source);
}

$names = array_keys($typedNames);
if ($names !== []) {
	$namesAlt = implode('|', $names);
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if ($file->getExtension() !== 'php') {
			continue;
		}

		$path = $file->getPathname();
		$source = file_get_contents($path);
		$source = preg_replace(
			'/->(' . $namesAlt . ')->value\b/',
			'->$1',
			$source,
			-1,
			$count,
		);
		$propRewrites += (int) $count;

		if ($count > 0) {
			file_put_contents($path, $source);
		}
	}
}

echo 'typed names harvested: ', implode(', ', $names), "\n";
echo "->typedProp->value rewrites: $propRewrites\n";

// the classes whose ::from / ::tryFrom / ::cases survive get a shim
$shim = <<<'PHP'

    public static function from($value)
    {
        if (!\in_array($value, self::cases(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown value "%s" for %s.', $value, self::class));
        }

        return $value;
    }

    public static function tryFrom($value)
    {
        return \in_array($value, self::cases(), true) ? $value : null;
    }

    public static function cases()
    {
        return \array_values((new \ReflectionClass(self::class))->getConstants());
    }
PHP;

$shimmed = ['TokenType', 'BsrConvention', 'NewlineConvention', 'PcreFeature'];
foreach ($shimmed as $class) {
	$matches = glob($root . '/**/' . $class . '.php') ?: [];
	$matches = array_merge($matches, glob($root . '/' . $class . '.php') ?: []);
	foreach ($matches as $path) {
		$source = file_get_contents($path);
		if (str_contains($source, 'function cases(')) {
			continue;
		}

		$pos = strrpos($source, '}');
		if ($pos === false) {
			continue;
		}

		file_put_contents($path, substr($source, 0, $pos) . $shim . "\n}\n");
		echo "shimmed: $path\n";
	}
}
