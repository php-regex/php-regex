<?php

declare(strict_types=1);

/**
 * Regenerates the downgraded enum files as cached-instance factories:
 * `GroupType::Capturing()` returns one shared instance, so ===, switch,
 * ->value, ->name, instanceof and the native GroupType type hints all keep
 * working on PHP 7.4 exactly as the enum did. The only rewrite the callers
 * need is `Enum::Case` becoming `Enum::Case()`.
 *
 * Reads the ORIGINAL enums through this checkout's autoloader and overwrites
 * the Rector-downgraded files under legacy/out/Parser.
 */

require __DIR__ . '/../vendor/autoload.php';

$enums = [
	'ErrorCode' => 'PHPRegex\Parser\ErrorCode',
	'TraversalAction' => 'PHPRegex\Parser\TraversalAction',
	'BsrConvention' => 'PHPRegex\Parser\BsrConvention',
	'NewlineConvention' => 'PHPRegex\Parser\NewlineConvention',
	'PcreFeature' => 'PHPRegex\Parser\PcreFeature',
	'TokenType' => 'PHPRegex\Parser\Token\TokenType',
	'LiteralConfidence' => 'PHPRegex\Parser\Analysis\LiteralConfidence',
	'Participation' => 'PHPRegex\Parser\Analysis\Participation',
	'AssertionKind' => 'PHPRegex\Parser\Hir\AssertionKind',
	'Greed' => 'PHPRegex\Parser\Hir\Greed',
	'LookKind' => 'PHPRegex\Parser\Hir\LookKind',
	'FormatRefusal' => 'PHPRegex\Parser\Printer\FormatRefusal',
	'QuantifierType' => 'PHPRegex\Parser\Node\QuantifierType',
	'ClassSetOperator' => 'PHPRegex\Parser\Node\ClassSetOperator',
	'GroupType' => 'PHPRegex\Parser\Node\GroupType',
	'CharLiteralType' => 'PHPRegex\Parser\Node\CharLiteralType',
	'ValidationErrorCategory' => 'PHPRegex\Parser\Validation\ValidationErrorCategory',
];

// enum methods ported as per-case maps: pure methods only, no arguments
$portedMethods = [
	'PcreFeature' => ['release'],
	'CharLiteralType' => ['label'],
];

$export = static fn ($value): string => var_export($value, true);

foreach ($enums as $short => $fqcn) {
	$reflection = new ReflectionEnum($fqcn);
	$isBacked = $reflection->isBacked();

	$namespace = (string) $reflection->getNamespaceName();
	$cases = [];
	foreach ($fqcn::cases() as $case) {
		$cases[] = [
			'name' => $case->name,
			'value' => $isBacked ? (string) $case->value : $case->name,
		];
	}

	$out = "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $namespace . ";\n\n";
	$out .= "/**\n * Cached-instance twin of the " . $short . " enum: GroupType::Capturing()\n";
	$out .= " * returns one shared instance, so identity comparisons, ->value and\n";
	$out .= " * native type hints keep the enum's semantics on PHP 7.4.\n */\n";
	$out .= "final class " . $short . "\n{\n";
	$out .= "    /** @var array<string, self> */\n    private static \$instances = [];\n\n";
	$out .= "    /** @var string */\n    public \$name;\n\n    /** @var string */\n    public \$value;\n\n";
	$out .= "    private function __construct(string \$name, string \$value)\n    {\n        \$this->name = \$name;\n        \$this->value = \$value;\n    }\n\n";

	foreach ($cases as $case) {
		$out .= "    public static function " . $case['name'] . "(): self\n    {\n";
		$out .= "        if (!isset(self::\$instances['" . $case['name'] . "'])) {\n";
		$out .= "            self::\$instances['" . $case['name'] . "'] = new self(" . $export($case['name']) . ", " . $export($case['value']) . ");\n";
		$out .= "        }\n\n        return self::\$instances['" . $case['name'] . "'];\n    }\n\n";
	}

	$out .= "    /** @return list<self> */\n    public static function cases(): array\n    {\n        return [\n";
	foreach ($cases as $case) {
		$out .= "            self::" . $case['name'] . "(),\n";
	}
	$out .= "        ];\n    }\n";

	if ($isBacked) {
		$out .= "\n    public static function from(string \$value): self\n    {\n";
		$out .= "        foreach (self::cases() as \$case) {\n            if (\$case->value === \$value) {\n                return \$case;\n            }\n        }\n\n";
		$out .= "        throw new \\InvalidArgumentException(sprintf('Unknown value %s for " . $short . ".', \$value));\n    }\n\n";
		$out .= "    public static function tryFrom(string \$value): ?self\n    {\n";
		$out .= "        foreach (self::cases() as \$case) {\n            if (\$case->value === \$value) {\n                return \$case;\n            }\n        }\n\n        return null;\n    }\n";
	}

	foreach ($portedMethods[$short] ?? [] as $method) {
		$map = [];
		$returnType = null;
		foreach ($fqcn::cases() as $case) {
			$result = $case->{$method}();
			$map[$case->name] = $result;
			$returnType = $returnType ?? (is_string($result) ? 'string' : '');
		}

		$out .= "\n    public function " . $method . "()" . ($returnType === 'string' ? ': string' : '') . "\n    {\n";
		$out .= "        \$perCase = " . $export($map) . ";\n\n";
		$out .= "        return \$perCase[\$this->name];\n    }\n";
	}

	$out .= "}\n";

	$target = __DIR__ . '/out/Parser/' . str_replace('\\', '/', substr($fqcn, strlen('PHPRegex\Parser\\'))) . '.php';
	file_put_contents($target, $out);
	echo 'emitted: ', $target, ' (', count($cases), " cases)\n";
}
