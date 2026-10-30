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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\ProjectConstants;
use PHPRegex\Linter\Extraction\ProjectConstantsAwareInterface;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A call naming a constant of another file reads it through the project's
 * constants, under the rules of the file: the pattern is reported once, at
 * its literal, in the file that writes it, with the call as its source.
 * Both strategies read the same.
 */
final class CrossFileConstantsTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const PATTERNS = <<<'PHP'
        <?php

        namespace App;

        final class Patterns
        {
            public const EMAIL = '/^[^@]+@[^@]+$/';
            public const PART = 'a';
            public const FULL = '/'.self::PART.'/';
            public const NOT_PROVEN = strtoupper('/x/');
        }

        class Base
        {
            public const OPEN = '/open/';
            final public const FIXED = '/fixed/';
        }

        enum Kind: string
        {
            case Re = '/enum/';
        }
        PHP;

    #[Test]
    public function test_a_constant_of_another_file_is_read_at_its_literal(): void
    {
        $reader = <<<'PHP'
            <?php

            namespace App\Service;

            use App\Patterns;
            use App\Kind as K;

            final class Reader
            {
                public function run(string $s): bool
                {
                    return 1 === preg_match(Patterns::EMAIL, $s)
                        && 1 === preg_match(\App\Patterns::FULL, $s)
                        && 1 === preg_match(Patterns::NOT_PROVEN, $s)
                        && 1 === preg_match(Patterns::MISSING, $s)
                        && 1 === preg_match(K::Re->value, $s)
                        && 1 === preg_match(\App\Nowhere::RE, $s);
                }
            }
            PHP;

        $this->assertSame([
            ['pattern' => '/^[^@]+@[^@]+$/', 'line' => 7, 'offset' => strpos(self::PATTERNS, "'/^[^@]"), 'source' => 'preg_match()', 'location' => 'via Patterns::EMAIL', 'file' => 'Patterns.php'],
            ['pattern' => '/a/', 'line' => 9, 'offset' => strpos(self::PATTERNS, "'/'.self::PART"), 'source' => 'preg_match()', 'location' => 'via \App\Patterns::FULL', 'file' => 'Patterns.php'],
            ['pattern' => '/enum/', 'line' => 21, 'offset' => strpos(self::PATTERNS, "'/enum/'"), 'source' => 'preg_match()', 'location' => 'via K::Re->value', 'file' => 'Patterns.php'],
        ], $this->readAcross(['Patterns.php' => self::PATTERNS, 'Reader.php' => $reader]));
    }

    /**
     * A constant of a parent class is named through that class: an
     * inherited constant read through static:: or self:: is not followed
     * up the hierarchy, in another file as in the file.
     */
    #[Test]
    public function test_a_parent_class_of_another_file_is_named_through_its_own_name(): void
    {
        $reader = <<<'PHP'
            <?php

            namespace App;

            class Child extends Base
            {
                public function run(string $s): bool
                {
                    return 1 === preg_match(Base::OPEN, $s) && 1 === preg_match(Base::FIXED, $s)
                        && 1 === preg_match(static::FIXED, $s) && 1 === preg_match(self::OPEN, $s) && 1 === preg_match(parent::FIXED, $s);
                }
            }
            PHP;

        $this->assertSame([
            ['pattern' => '/open/', 'line' => 15, 'offset' => strpos(self::PATTERNS, "'/open/'"), 'source' => 'preg_match()', 'location' => 'via Base::OPEN', 'file' => 'Patterns.php'],
            ['pattern' => '/fixed/', 'line' => 16, 'offset' => strpos(self::PATTERNS, "'/fixed/'"), 'source' => 'preg_match()', 'location' => 'via Base::FIXED', 'file' => 'Patterns.php'],
        ], $this->readAcross(['Patterns.php' => self::PATTERNS, 'Child.php' => $reader]));
    }

    #[Test]
    public function test_the_class_of_the_file_wins_over_a_class_of_the_project(): void
    {
        $reader = <<<'PHP'
            <?php

            namespace App;

            final class Patterns
            {
                public const EMAIL = '/own/';
            }

            preg_match(Patterns::EMAIL, 'x');
            preg_match(Patterns::FULL, 'x');
            PHP;

        $this->assertSame([
            ['pattern' => '/own/', 'line' => 7, 'offset' => strpos($reader, "'/own/'"), 'source' => 'preg_match()', 'location' => 'via Patterns::EMAIL', 'file' => 'Reader.php'],
        ], $this->readAcross(['Patterns.php' => self::PATTERNS, 'Reader.php' => $reader]));
    }

    /**
     * self:: inside a trait names the class that uses it, which the file
     * does not know: nothing is read, in another file as in the file.
     */
    #[Test]
    public function test_self_inside_a_trait_names_no_class(): void
    {
        $trait = "<?php\n\nnamespace App;\n\ntrait Reads\n{\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(self::EMAIL, \$s);\n    }\n}\n";

        $this->assertSame([], $this->readAcross(['Patterns.php' => self::PATTERNS, 'Reads.php' => $trait]));
    }

    /**
     * A literal of the file and one of another file may sit at the same
     * offset: each is reported, in its file, and each once.
     */
    #[Test]
    public function test_literals_of_two_files_at_one_offset_are_both_reported(): void
    {
        $a = "<?php\n\nfinal class A { public const RE = '/x/'; }\n";
        $b = "<?php\n\nfinal class B { public const RE = '/x/'; }\npreg_match(A::RE, 'y');\npreg_match(B::RE, 'y');\npreg_match(A::RE, 'z');\n";
        $this->assertSame(strpos($a, "'/x/'"), strpos($b, "'/x/'"));

        $this->assertSame([
            ['pattern' => '/x/', 'line' => 3, 'offset' => strpos($a, "'/x/'"), 'source' => 'preg_match()', 'location' => 'via A::RE', 'file' => 'A.php'],
            ['pattern' => '/x/', 'line' => 3, 'offset' => strpos($b, "'/x/'"), 'source' => 'preg_match()', 'location' => 'via B::RE', 'file' => 'B.php'],
        ], $this->readAcross(['A.php' => $a, 'B.php' => $b]));
    }

    #[Test]
    public function test_a_class_declared_in_two_files_reads_nothing(): void
    {
        $twin = "<?php\n\nnamespace App;\n\nfinal class Patterns\n{\n    public const EMAIL = '/twin/';\n}\n";
        $reader = "<?php\n\nuse App\\Patterns;\n\npreg_match(Patterns::EMAIL, 'x');\n";

        $this->assertSame([], $this->readAcross(['Patterns.php' => self::PATTERNS, 'Twin.php' => $twin, 'Reader.php' => $reader]));
    }

    /**
     * A constant folded through another file: the chain of constants is
     * followed across files, its depth capped as in a file, and a cycle
     * across two files reads nothing.
     */
    #[Test]
    public function test_constants_fold_across_files(): void
    {
        $a = "<?php\n\nnamespace App;\n\nfinal class A\n{\n    public const RE = '/'.B::PART.'/';\n    public const LOOP = B::LOOP;\n}\n";
        $b = "<?php\n\nnamespace App;\n\nfinal class B\n{\n    public const PART = 'b';\n    public const LOOP = A::LOOP;\n}\n";
        $reader = "<?php\n\nuse App\\A;\n\npreg_match(A::RE, 'x');\npreg_match(A::LOOP, 'x');\n";

        $this->assertSame([
            ['pattern' => '/b/', 'line' => 7, 'offset' => strpos($a, "'/'.B::PART"), 'source' => 'preg_match()', 'location' => 'via A::RE', 'file' => 'A.php'],
        ], $this->readAcross(['A.php' => $a, 'B.php' => $b, 'Reader.php' => $reader]));
    }

    #[Test]
    public function test_an_array_constant_of_another_file_feeds_a_call_its_patterns(): void
    {
        $map = "<?php\n\nnamespace App;\n\nfinal class Map\n{\n    public const REPLACEMENTS = ['/a/' => 'x', '/b/' => 'y'];\n}\n";
        $reader = "<?php\n\nuse App\\Map;\n\npreg_replace(array_keys(Map::REPLACEMENTS), [], 'x');\npreg_replace_callback_array(Map::REPLACEMENTS, 'x');\n";

        $this->assertSame([
            ['pattern' => '/a/', 'line' => 7, 'offset' => strpos($map, "'/a/'"), 'source' => 'preg_replace_callback_array()', 'location' => 'via Map::REPLACEMENTS', 'file' => 'Map.php'],
            ['pattern' => '/b/', 'line' => 7, 'offset' => strpos($map, "'/b/'"), 'source' => 'preg_replace_callback_array()', 'location' => 'via Map::REPLACEMENTS', 'file' => 'Map.php'],
        ], $this->readAcross(['Map.php' => $map, 'Reader.php' => $reader]));
    }

    /**
     * A literal of another file is judged by the version guards of its own
     * file, as a literal of the file is by its own: the guard around the
     * call does not narrow it, nor does one of the reading file that spans
     * the offset the literal is at in its file.
     */
    #[Test]
    public function test_a_literal_of_another_file_is_judged_by_the_guards_of_its_own_file(): void
    {
        $patterns = "<?php\n\nif (PHP_VERSION_ID >= 80100) {\n    final class Guarded\n    {\n        public const RE = '/g/';\n    }\n}\n\nfinal class Free\n{\n    public const RE = '/f/';\n}\n";
        $reader = "<?php\n\nif (PHP_VERSION_ID >= 80400) {\n    preg_match(Guarded::RE, 'x');\n    preg_match(Free::RE, 'x');\n    preg_match('/own/', 'x');\n    // The block spans every offset a literal of the other file is at.\n    //".str_repeat(' ', 160)."\n}\n";
        $this->assertGreaterThan(strpos($patterns, "'/f/'"), strrpos($reader, '}'));

        $this->assertSame([
            ['/f/', 'Patterns.php', null],
            ['/g/', 'Patterns.php', '>=8.1'],
            ['/own/', 'Reader.php', '>=8.4'],
        ], $this->guardsAcross(['Patterns.php' => $patterns, 'Reader.php' => $reader]));
    }

    /**
     * Without the project's constants, a constant of another file is not
     * read, as before.
     */
    #[Test]
    public function test_without_the_project_constants_another_file_is_not_read(): void
    {
        $reader = "<?php\n\nuse App\\Patterns;\n\npreg_match(Patterns::EMAIL, 'x');\n";
        $directory = $this->makeProject(['Patterns.php' => self::PATTERNS, 'Reader.php' => $reader]);

        $this->assertSame([], $this->readWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), [$directory.'/Reader.php']));
    }

    /**
     * Each strategy, handed the constants of every file, reads the files;
     * both must read the same.
     *
     * @param array<string, string> $files relative path => contents
     *
     * @return list<array{pattern: string, line: int, offset: int|null, source: string, location: string|null, file: string}>
     */
    private function readAcross(array $files): array
    {
        $directory = $this->makeProject($files);
        $paths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($files));
        $constants = ProjectConstants::of(array_map(ProjectConstants::scanFile(...), $paths));

        $fromTokens = $this->readWith($this->withConstants(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths);
        if (class_exists(ParserFactory::class)) {
            $fromAst = $this->readWith($this->withConstants(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths);
            $this->assertSame($fromAst, $fromTokens, 'The token strategy reads otherwise than the parser strategy.');
        }

        return $fromTokens;
    }

    /**
     * Each strategy, handed the constants of every file, reads the files:
     * each pattern, its file and the versions its guards keep; both must
     * read the same.
     *
     * @param array<string, string> $files relative path => contents
     *
     * @return list<array{string, string, string|null}>
     */
    private function guardsAcross(array $files): array
    {
        $directory = $this->makeProject($files);
        $paths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($files));
        $constants = ProjectConstants::of(array_map(ProjectConstants::scanFile(...), $paths));

        $fromTokens = $this->guardsWith($this->withConstants(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths);
        if (class_exists(ParserFactory::class)) {
            $fromAst = $this->guardsWith($this->withConstants(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths);
            $this->assertSame($fromAst, $fromTokens, 'The token strategy reads otherwise than the parser strategy.');
        }

        return $fromTokens;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<array{string, string, string|null}>
     */
    private function guardsWith(ExtractorInterface $strategy, array $paths): array
    {
        $read = [];
        foreach ($strategy->extract($paths) as $occurrence) {
            $this->assertInstanceOf(PatternOccurrence::class, $occurrence);
            $guard = $occurrence->guard;
            $read[] = [$occurrence->pattern, basename($occurrence->file), $guard instanceof TargetConstraint ? $guard->phpConstraint() : null];
        }
        sort($read);

        return $read;
    }

    private function withConstants(ExtractorInterface $strategy, ProjectConstants $constants): ExtractorInterface
    {
        $this->assertInstanceOf(ProjectConstantsAwareInterface::class, $strategy);

        return $strategy->withProjectConstants($constants);
    }
}
