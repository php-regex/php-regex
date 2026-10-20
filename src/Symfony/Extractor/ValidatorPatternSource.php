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

namespace PHPRegex\Symfony\Extractor;

use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Regex as SymfonyRegex;
use Symfony\Component\Validator\Exception\ExceptionInterface;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\GetterMetadata;
use Symfony\Component\Validator\Mapping\Loader\XmlFileLoader;
use Symfony\Component\Validator\Mapping\Loader\YamlFileLoader;

/**
 * Reads the Regex constraints of the validator's mapping files: the YAML
 * and XML files FrameworkBundle hands the validator builder, each read
 * with the validator's own loader, as the validator reads it. A constraint
 * written as an attribute is in no such file: the PHP files give it.
 *
 * Each pattern is reported at its mapping file, on the line that writes
 * it; a pattern written over several lines, which no line spells whole, on
 * the line of its class. A file the validator cannot read, or that maps a
 * property its class lacks, is reported as a file the lint could not read,
 * with the validator's message: the validator would refuse it too.
 *
 * @internal
 */
final class ValidatorPatternSource implements PatternSourceInterface
{
    /**
     * @param list<string> $mappingFiles the files the validator builder reads, as FrameworkBundle gives them
     */
    public function __construct(private readonly array $mappingFiles = []) {}

    public function getName(): string
    {
        return 'validators';
    }

    public function isSupported(): bool
    {
        return [] !== $this->mappingFiles && class_exists(ClassMetadata::class);
    }

    public function extract(PatternSourceContext $context): array
    {
        if (!$this->isSupported()) {
            return [];
        }

        $patterns = [];
        foreach ($this->mappingFiles as $file) {
            if (self::isExcluded($file, $context)) {
                continue;
            }

            $patterns = [...$patterns, ...self::extractFromFile($file)];
        }

        return $patterns;
    }

    /**
     * @return list<PatternOccurrence>
     */
    private static function extractFromFile(string $file): array
    {
        $xml = 'xml' === strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        $loader = $xml ? new XmlFileLoader($file) : new YamlFileLoader($file);

        // The validator's exceptions, and the \InvalidArgumentException its
        // YAML loader throws for a file that is no YAML mapping.
        try {
            $constraints = self::readConstraints($loader);
        } catch (ExceptionInterface|\InvalidArgumentException $exception) {
            return [new PatternOccurrence('', $file, 1, 'validator', unread: $exception->getMessage())];
        }

        $lines = @file($file, \FILE_IGNORE_NEW_LINES);
        $lines = false === $lines ? [] : $lines;

        $patterns = [];
        $spelled = [];
        foreach ($constraints as [$className, $member, $pattern]) {
            $nth = $spelled[$pattern] = ($spelled[$pattern] ?? -1) + 1;
            $line = self::linesSpelling($lines, $pattern, $xml)[$nth] ?? self::classLine($lines, $className, $xml);
            $patterns[] = new PatternOccurrence($pattern, $file, $line, 'validator:'.$className.$member);
        }

        return $patterns;
    }

    /**
     * The patterns of the Regex constraints the file maps, nested ones
     * included, in the order of its classes and their members.
     *
     * @throws ExceptionInterface        when the validator cannot read the file
     * @throws \InvalidArgumentException when the file is no YAML mapping
     *
     * @return list<array{string, string, string}> each constraint's class, member ("::$name" or "::getName()") and pattern
     */
    private static function readConstraints(XmlFileLoader|YamlFileLoader $loader): array
    {
        $read = [];
        foreach ($loader->getMappedClasses() as $className) {
            if (!\is_string($className)) {
                continue;
            }

            $metadata = new ClassMetadata($className);
            $loader->loadClassMetadata($metadata);

            // A Regex constraint, nested or not, cannot be put on a class:
            // the loader refuses it, so only the members are read.
            foreach ($metadata->getConstrainedProperties() as $property) {
                foreach ($metadata->getPropertyMetadata($property) as $member) {
                    $name = $member instanceof GetterMetadata ? '::'.$member->getName().'()' : '::$'.$member->getPropertyName();
                    foreach (self::patterns($member->getConstraints()) as $pattern) {
                        $read[] = [$className, $name, $pattern];
                    }
                }
            }
        }

        return $read;
    }

    /**
     * The patterns of the Regex constraints among the values, and among
     * those nested in the options of the other constraints: All,
     * AtLeastOneOf, Sequentially, When and Collection hold theirs in public
     * options, a list or a map of constraints.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function patterns(array $values): array
    {
        $patterns = [];
        foreach ($values as $value) {
            if ($value instanceof SymfonyRegex) {
                if (null !== $value->pattern && '' !== $value->pattern) {
                    $patterns[] = $value->pattern;
                }
            } elseif ($value instanceof Constraint) {
                $patterns = [...$patterns, ...self::patterns(get_object_vars($value))];
            } elseif (\is_array($value)) {
                $patterns = [...$patterns, ...self::patterns($value)];
            }
        }

        return $patterns;
    }

    /**
     * The lines, 1-based, that write the pattern, once per time a line
     * writes it: as it is, or as the format escapes it, between the marks
     * that may stand around a value.
     *
     * @param array<int, string> $lines
     *
     * @return list<int>
     */
    private static function linesSpelling(array $lines, string $pattern, bool $xml): array
    {
        $spellings = $xml
            ? [$pattern, strtr($pattern, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']), htmlspecialchars($pattern, \ENT_XML1 | \ENT_QUOTES, 'UTF-8')]
            : [$pattern, str_replace("'", "''", $pattern), substr((string) json_encode($pattern, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 1, -1)];
        $before = $xml ? ">[ \t" : " \t'\":[{,";
        $after = $xml ? "<] \t" : " \t'\",]}#";

        $found = [];
        foreach ($lines as $index => $line) {
            $times = 0;
            foreach (array_unique($spellings) as $spelling) {
                $times = max($times, self::timesWritten($line, $spelling, $before, $after));
            }

            for ($i = 0; $i < $times; $i++) {
                $found[] = $index + 1;
            }
        }

        return $found;
    }

    /**
     * How many times the line writes the value whole: preceded by the
     * start of the line or one of $before, followed by its end or one of
     * $after.
     */
    private static function timesWritten(string $line, string $value, string $before, string $after): int
    {
        $times = 0;
        $offset = 0;
        $length = \strlen($value);
        while ('' !== $value && false !== ($position = strpos($line, $value, $offset))) {
            $end = $position + $length;
            if ((0 === $position || str_contains($before, $line[$position - 1])) && ($end === \strlen($line) || str_contains($after, $line[$end]))) {
                $times++;
            }
            $offset = $position + 1;
        }

        return $times;
    }

    /**
     * The line, 1-based, that opens the class's mapping; the first line of
     * the file when none does.
     *
     * @param array<int, string> $lines
     */
    private static function classLine(array $lines, string $className, bool $xml): int
    {
        $name = htmlspecialchars($className, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        foreach ($lines as $index => $line) {
            $text = trim($line, " \t");
            $opens = $xml
                ? str_starts_with($text, '<class') && str_contains($text, 'name="'.$name.'"')
                : str_ends_with($text, ':') && 1 === self::timesWritten(substr($text, 0, -1), $className, "'\"", "'\"");
            if ($opens) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * Whether the file lies in a directory the lint excludes: under the
     * directory the excludes are read from, or, without one, anywhere in
     * its path.
     */
    private static function isExcluded(string $file, PatternSourceContext $context): bool
    {
        $file = str_replace('\\', '/', realpath($file) ?: $file);
        $base = null === $context->excludeBase ? null : rtrim(str_replace('\\', '/', realpath($context->excludeBase) ?: $context->excludeBase), '/');

        foreach ($context->excludePaths as $exclude) {
            $exclude = trim(str_replace('\\', '/', $exclude), '/');
            if ('' === $exclude) {
                continue;
            }

            if (null === $base ? str_contains($file, '/'.$exclude.'/') : str_starts_with($file, $base.'/'.$exclude.'/')) {
                return true;
            }
        }

        return false;
    }
}
