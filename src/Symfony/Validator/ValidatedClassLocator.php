<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Symfony\Validator;

use Symfony\Component\Validator\Exception\ExceptionInterface;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Symfony\Component\Validator\Mapping\Loader\XmlFileLoader;
use Symfony\Component\Validator\Mapping\Loader\YamlFileLoader;

/**
 * The classes the validator maps with a Regex constraint, read through the
 * application's own metadata factory, so attributes, mapping files and
 * loaders count as the validator counts them: the classes the mapping
 * files name, and those a PHP file of the given directories declares when
 * it names the constraint: "Regex" as an attribute, or "\Regex" as an
 * import.
 *
 * @internal
 */
final class ValidatedClassLocator
{
    /**
     * @param list<string> $mappingFiles the files the validator builder reads, as FrameworkBundle gives them
     */
    public function __construct(
        private readonly ?MetadataFactoryInterface $metadataFactory,
        private readonly array $mappingFiles = [],
    ) {}

    public function isSupported(): bool
    {
        return null !== $this->metadataFactory;
    }

    /**
     * @param list<string> $classes     the classes to read; none reads the mapping files and directories
     * @param list<string> $directories where to look for PHP classes
     *
     * @return array{list<ClassMetadataInterface>, list<string>} the metadata, and why a class was not read
     */
    public function locate(array $classes = [], array $directories = []): array
    {
        if (null === $this->metadataFactory) {
            return [[], []];
        }

        if ([] === $classes) {
            $classes = [...$this->mappedClasses(), ...self::declaredClasses($directories)];
        }

        $metadata = [];
        $unread = [];
        foreach (array_values(array_unique($classes)) as $class) {
            try {
                if (!class_exists($class)) {
                    $unread[] = \sprintf('%s: the class does not exist.', $class);

                    continue;
                }

                $read = $this->metadataFactory->getMetadataFor($class);
            } catch (ExceptionInterface|\Error $e) {
                $unread[] = \sprintf('%s: %s', $class, $e->getMessage());

                continue;
            }

            if ($read instanceof ClassMetadataInterface) {
                $metadata[] = $read;
            }
        }

        return [$metadata, $unread];
    }

    /**
     * @return list<string>
     */
    private function mappedClasses(): array
    {
        $classes = [];
        foreach ($this->mappingFiles as $file) {
            if (!is_file($file)) {
                continue;
            }

            $loader = 'xml' === strtolower(pathinfo($file, \PATHINFO_EXTENSION)) ? new XmlFileLoader($file) : new YamlFileLoader($file);

            try {
                foreach ($loader->getMappedClasses() as $class) {
                    if (\is_string($class)) {
                        $classes[] = $class;
                    }
                }
            } catch (ExceptionInterface|\InvalidArgumentException) {
                continue;
            }
        }

        return $classes;
    }

    /**
     * The classes the PHP files of the directories declare, in the files
     * that write the Regex constraint.
     *
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private static function declaredClasses(array $directories): array
    {
        $classes = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }

                $code = @file_get_contents($file->getPathname());
                if (false === $code || !str_contains($code, 'Regex')) {
                    continue;
                }

                $tokens = token_get_all($code);
                if (self::writesTheConstraint($tokens)) {
                    $classes = [...$classes, ...self::classesIn($tokens)];
                }
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Whether the code names the Regex constraint: "Regex" alone, as an
     * attribute or a call, or a name ending in "\Regex", as an import.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function writesTheConstraint(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
                && ('Regex' === $token[1] || str_ends_with($token[1], '\\Regex'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes the code declares, with their namespace.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<string>
     */
    private static function classesIn(array $tokens): array
    {
        $classes = [];
        $namespace = '';
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && \is_array($tokens[$j]); $j++) {
                    if (\in_array($tokens[$j][0], [\T_NAME_QUALIFIED, \T_STRING], true)) {
                        $namespace = $tokens[$j][1];

                        break;
                    }
                }
            } elseif (\T_CLASS === $token[0]) {
                // "::class" and "new class" name no declaration.
                $before = $i;
                do {
                    $before--;
                } while ($before >= 0 && \is_array($tokens[$before]) && \T_WHITESPACE === $tokens[$before][0]);
                if ($before >= 0 && \is_array($tokens[$before]) && \in_array($tokens[$before][0], [\T_DOUBLE_COLON, \T_NEW], true)) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    if (\is_array($tokens[$j]) && \T_WHITESPACE === $tokens[$j][0]) {
                        continue;
                    }

                    if (\is_array($tokens[$j]) && \T_STRING === $tokens[$j][0]) {
                        $classes[] = ('' === $namespace ? '' : $namespace.'\\').$tokens[$j][1];
                    }

                    break;
                }
            }
        }

        return $classes;
    }
}
