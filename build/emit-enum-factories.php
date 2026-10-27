<?php

declare(strict_types=1);

/**
 * Writes each enum of src/Parser as a class of cached instances:
 * `GroupType::Capturing()` returns one shared instance, so ===, ->value,
 * ->name, instanceof and the native GroupType type hints keep working on
 * PHP 7.4 as the enum did. The callers' only rewrite is `Enum::Case`
 * becoming `Enum::Case()`.
 *
 * What an enum is on PHP 8.1 the twin spells out:
 *
 * - the classes implement PHPRegex\Parser\UnitEnum, and the backed ones
 *   PHPRegex\Parser\BackedEnum, the twin's \UnitEnum and \BackedEnum (the
 *   native interfaces cannot be implemented by a class);
 * - from() throws the \ValueError PHP throws, with its message;
 * - a pure enum has no value, a backed one its value with its type;
 * - a method of the enum with no parameter is ported as a map per case;
 * - PHPRegex\Parser\EnumCases lists the classes, for the cache to restore
 *   them, and gives back the shared instance of a restored case.
 *
 * Each enum is read from the original through this checkout's autoloader.
 * An enum with a constant, an interface, a static method or a method with
 * parameters stops the build: it needs a port written by hand.
 */
require __DIR__ . '/../vendor/autoload.php';

$source = __DIR__ . '/../src/Parser';
$target = __DIR__ . '/php74/Parser';

$enums = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}

	$relative = substr($file->getPathname(), strlen($source) + 1, -4);
	$class = 'PHPRegex\\Parser\\' . str_replace('/', '\\', $relative);
	if (enum_exists($class)) {
		$enums[$class] = $relative;
	}
}

ksort($enums);
if ($enums === []) {
	fwrite(STDERR, "emit-enum-factories: no enum found under src/Parser\n");
	exit(1);
}

$export = static fn ($value): string => var_export($value, true);

foreach ($enums as $fqcn => $relative) {
	$reflection = new ReflectionEnum($fqcn);
	$short = $reflection->getShortName();
	$backing = $reflection->isBacked() ? (string) $reflection->getBackingType() : null;

	$interfaces = array_diff($reflection->getInterfaceNames(), ['UnitEnum', 'BackedEnum']);
	if ($interfaces !== []) {
		stop($fqcn, 'implements ' . implode(', ', $interfaces));
	}

	$constants = array_filter($reflection->getReflectionConstants(), static fn (ReflectionClassConstant $constant): bool => !$constant->isEnumCase());
	if ($constants !== []) {
		stop($fqcn, 'declares constants');
	}

	$ported = [];
	foreach ($reflection->getMethods() as $method) {
		if ($method->getDeclaringClass()->getName() !== $fqcn || in_array($method->getName(), ['cases', 'from', 'tryFrom'], true)) {
			continue;
		}

		if ($method->isStatic() || $method->getNumberOfParameters() !== 0) {
			stop($fqcn, 'declares ' . $method->getName() . '(), which is static or takes parameters');
		}

		$ported[] = $method;
	}

	$out = "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $reflection->getNamespaceName() . ";\n\n";
	$out .= "/**\n * The " . $short . " enum as a class of shared instances: " . $short . '::'
		. $reflection->getCases()[0]->getName() . "() returns\n * the same instance at each call.\n */\n";
	$out .= 'final class ' . $short . ' implements \\PHPRegex\\Parser\\' . ($backing === null ? 'UnitEnum' : 'BackedEnum') . "\n{\n";
	$out .= "    /** @var array<string, self> */\n    private static array \$instances = [];\n\n";
	$out .= "    public string \$name;\n\n";
	if ($backing !== null) {
		$out .= "    public {$backing} \$value;\n\n";
		$out .= "    private function __construct(string \$name, {$backing} \$value)\n    {\n        \$this->name = \$name;\n        \$this->value = \$value;\n    }\n\n";
	} else {
		$out .= "    private function __construct(string \$name)\n    {\n        \$this->name = \$name;\n    }\n\n";
	}

	foreach ($reflection->getCases() as $case) {
		$name = $case->getName();
		$arguments = $export($name) . ($backing === null ? '' : ', ' . $export($case->getBackingValue()));
		$out .= "    public static function {$name}(): self\n    {\n";
		$out .= "        return self::\$instances['{$name}'] ??= new self({$arguments});\n    }\n\n";
	}

	$out .= "    /**\n     * @return list<self>\n     */\n    public static function cases(): array\n    {\n        return [\n";
	foreach ($reflection->getCases() as $case) {
		$out .= '            self::' . $case->getName() . "(),\n";
	}
	$out .= "        ];\n    }\n";

	if ($backing !== null) {
		$quote = $backing === 'string' ? "'\"'.\$value.'\"'" : '$value';
		$out .= "\n    public static function from({$backing} \$value): self\n    {\n";
		$out .= "        \$case = self::tryFrom(\$value);\n        if (null === \$case) {\n";
		$out .= "            throw new \\ValueError({$quote}.' is not a valid backing value for enum " . addslashes($fqcn) . "');\n        }\n\n";
		$out .= "        return \$case;\n    }\n\n";
		$out .= "    public static function tryFrom({$backing} \$value): ?self\n    {\n";
		$out .= "        foreach (self::cases() as \$case) {\n            if (\$case->value === \$value) {\n                return \$case;\n            }\n        }\n\n        return null;\n    }\n";
	}

	foreach ($ported as $method) {
		$map = [];
		foreach ($reflection->getCases() as $case) {
			$map[$case->getName()] = $case->getValue()->{$method->getName()}();
		}

		$returns = $method->getReturnType();
		$native = $returns instanceof ReflectionNamedType && in_array($returns->getName(), ['string', 'int', 'bool', 'float', 'array'], true)
			? ': ' . ($returns->allowsNull() ? '?' : '') . $returns->getName()
			: '';
		$visibility = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');
		$out .= "\n    {$visibility} function " . $method->getName() . "(){$native}\n    {\n";
		$out .= '        $perCase = ' . $export($map) . ";\n\n";
		$out .= "        return \$perCase[\$this->name];\n    }\n";
	}

	$out .= "}\n";

	$path = $target . '/' . $relative . '.php';
	if (!is_file($path)) {
		stop($fqcn, 'has no file in the twin at ' . $path);
	}

	file_put_contents($path, $out);
}

$classes = '';
foreach (array_keys($enums) as $fqcn) {
	$classes .= '        \\' . $fqcn . "::class,\n";
}

file_put_contents($target . '/UnitEnum.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * What \UnitEnum is to an enum, for the classes the twin writes in place of
 * the enums: PHP lets no class implement \UnitEnum.
 *
 * @property-read string $name
 *
 * @internal
 */
interface UnitEnum
{
    /**
     * @return list<static>
     */
    public static function cases(): array;
}

PHP);

file_put_contents($target . '/BackedEnum.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * What \BackedEnum is to a backed enum, for the classes the twin writes in
 * place of the enums.
 *
 * @property-read int|string $value
 *
 * @method static static      from(int|string $value)
 * @method static static|null tryFrom(int|string $value)
 *
 * @internal
 */
interface BackedEnum extends UnitEnum
{
}

PHP);

file_put_contents($target . '/EnumCases.php', <<<PHP
<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * The classes the twin writes in place of the enums, and the way back to the
 * shared instance of a case a cache restored: unserialize() builds a new
 * object where PHP 8.1 gives the enum case itself, and a comparison by
 * identity tells them apart.
 *
 * @internal
 */
final class EnumCases
{
    /**
     * @var list<class-string<UnitEnum>>
     */
    public const CLASSES = [
{$classes}    ];

    /**
     * The value with each case it holds, at any depth, replaced by the
     * shared instance of that case.
     *
     * @param mixed \$value
     *
     * @return mixed
     */
    public static function shared(\$value)
    {
        return self::walk(\$value, new \\SplObjectStorage());
    }

    /**
     * @param mixed                             \$value
     * @param \\SplObjectStorage<object, mixed> \$seen
     *
     * @return mixed
     */
    private static function walk(\$value, \\SplObjectStorage \$seen)
    {
        if (\$value instanceof UnitEnum) {
            foreach (\$value::cases() as \$case) {
                if (\$case->name === \$value->name) {
                    return \$case;
                }
            }

            return \$value;
        }

        if (\\is_array(\$value)) {
            foreach (\$value as \$key => \$item) {
                \$value[\$key] = self::walk(\$item, \$seen);
            }

            return \$value;
        }

        // array access, not contains() and attach(): PHP 8.5 deprecates them
        if (!\\is_object(\$value) || isset(\$seen[\$value])) {
            return \$value;
        }

        \$seen[\$value] = true;
        for (\$class = new \\ReflectionObject(\$value); false !== \$class; \$class = \$class->getParentClass()) {
            foreach (\$class->getProperties() as \$property) {
                if (\$property->isStatic() || \$property->getDeclaringClass()->getName() !== \$class->getName()) {
                    continue;
                }

                // needed before PHP 8.1, deprecated from 8.5
                if (\\PHP_VERSION_ID < 80100) {
                    \$property->setAccessible(true);
                }
                if (!\$property->isInitialized(\$value)) {
                    continue;
                }

                \$item = \$property->getValue(\$value);
                \$shared = self::walk(\$item, \$seen);
                if (\$shared !== \$item) {
                    \$property->setValue(\$value, \$shared);
                }
            }
        }

        return \$value;
    }
}

PHP);

echo 'enums written as classes: ', count($enums), "\n";

function stop(string $fqcn, string $why): void
{
	fwrite(STDERR, "emit-enum-factories: $fqcn $why\n");
	exit(1);
}
