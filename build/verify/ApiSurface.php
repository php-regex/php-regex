<?php

declare(strict_types=1);

namespace PHPRegex\Build\Verify;

/**
 * The public surface of the parser package as a consumer reaches it, read
 * by reflection the same way from either copy: each class, interface and
 * trait with its parent and interfaces, its public and protected methods
 * with their parameters (name, optional, variadic, by reference), its
 * public properties and constants; an enum as its cases, and the twin's
 * class of cases as the cases it gives. Types are left out: the twin
 * declares in PHPDoc what PHP 7.4 cannot declare.
 *
 * The file is PHP 7.4 code: it runs against the twin.
 */
final class ApiSurface
{
    /**
     * The classes the twin adds to stand for what PHP 8.1 has built in.
     */
    private const TWIN_ONLY = ['PHPRegex\\Parser\\EnumCases', 'PHPRegex\\Parser\\UnitEnum', 'PHPRegex\\Parser\\BackedEnum'];

    /**
     * @return array<string, mixed>
     */
    public static function of(string $directory): array
    {
        $surface = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension() || 0 === strpos($file->getFilename(), '.')) {
                continue;
            }

            $class = 'PHPRegex\\Parser\\'.str_replace('/', '\\', substr($file->getPathname(), \strlen($directory) + 1, -4));
            if (\in_array($class, self::TWIN_ONLY, true) || (!class_exists($class) && !interface_exists($class) && !trait_exists($class))) {
                continue;
            }

            $surface[$class] = self::classSurface(new \ReflectionClass($class));
        }

        ksort($surface);

        return $surface;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return array<string, mixed>
     */
    private static function classSurface(\ReflectionClass $class): array
    {
        if (self::isEnum($class)) {
            $cases = [];
            foreach ($class->getMethod('cases')->invoke(null) as $case) {
                $cases[$case->name] = property_exists($case, 'value') || $case instanceof \PHPRegex\Parser\BackedEnum ? $case->value : null;
            }

            return ['kind' => 'enum', 'cases' => $cases, 'methods' => self::methods($class, ['cases', 'from', 'tryFrom'], array_keys($cases))];
        }

        $interfaces = array_values(array_diff($class->getInterfaceNames(), ['Stringable', 'UnitEnum', 'BackedEnum']));
        sort($interfaces);

        $properties = [];
        foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === $class->getName()) {
                $properties[$property->getName()] = $property->isStatic() ? 'static' : 'instance';
            }
        }

        ksort($properties);

        $constants = [];
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->isPublic() && $constant->getDeclaringClass()->getName() === $class->getName()) {
                $constants[] = $constant->getName();
            }
        }

        sort($constants);

        return [
            'kind' => $class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : 'class'),
            'final' => $class->isFinal(),
            'abstract' => $class->isAbstract() && !$class->isInterface(),
            'parent' => false === $class->getParentClass() ? null : $class->getParentClass()->getName(),
            'interfaces' => $interfaces,
            'constants' => $constants,
            'properties' => $properties,
            'methods' => self::methods($class, [], []),
        ];
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<string>              $skipped
     * @param list<string>              $cases
     *
     * @return array<string, mixed>
     */
    private static function methods(\ReflectionClass $class, array $skipped, array $cases): array
    {
        $methods = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
            $name = $method->getName();
            if ($method->getDeclaringClass()->getName() !== $class->getName() || \in_array($name, $skipped, true) || \in_array($name, $cases, true)) {
                continue;
            }

            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = $parameter->getName().($parameter->isOptional() ? '?' : '').($parameter->isVariadic() ? '...' : '').($parameter->isPassedByReference() ? '&' : '');
            }

            $methods[$name] = [
                'visibility' => $method->isPublic() ? 'public' : 'protected',
                'static' => $method->isStatic(),
                'abstract' => $method->isAbstract(),
                'final' => $method->isFinal(),
                'parameters' => $parameters,
            ];
        }

        ksort($methods);

        return $methods;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function isEnum(\ReflectionClass $class): bool
    {
        return (interface_exists('PHPRegex\\Parser\\UnitEnum') && $class->implementsInterface('PHPRegex\\Parser\\UnitEnum'))
            || (\PHP_VERSION_ID >= 80100 && method_exists($class, 'isEnum') && $class->isEnum());
    }
}
