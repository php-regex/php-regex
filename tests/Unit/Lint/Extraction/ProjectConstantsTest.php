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

use PHPRegex\Linter\Extraction\ProjectConstants;
use PHPRegex\Linter\Extraction\ValueExpr;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The class constants and enum cases of the project's files, read once
 * before the patterns: what a call of another file may name.
 */
final class ProjectConstantsTest extends TestCase
{
    use TemporaryProject;

    private const PATTERNS = <<<'PHP'
        <?php

        namespace App;

        use App\Support\Other;

        final class Patterns
        {
            public const EMAIL = '/^[^@]+@[^@]+$/';
            public const PART = 'a';
            public const FULL = '/'.self::PART.'/';
            public const LIST = ['/x/', 'k' => '/y/'];
            public const SIZE = 10;
            public const FROM_ELSEWHERE = Other::RE;
            public const GLOBAL = \APP_RE;
            final public const FIXED = '/f/';
        }

        class Base
        {
            protected const RE = '/b/';
        }

        interface HasPattern
        {
            public const RE = '/i/';
        }

        enum Kind: string
        {
            case Re = '/e/';
            case Plain = 'plain';
            const ALIAS = self::Re->value;
        }
        PHP;

    #[Test]
    public function test_a_file_contributes_its_classes_and_their_provable_constants(): void
    {
        $contribution = ProjectConstants::scan(self::PATTERNS, '/p/src/Patterns.php');
        $constants = ProjectConstants::of([$contribution]);

        $this->assertTrue($constants->isFinal('app\patterns'));
        $this->assertFalse($constants->isFinal('app\base'));
        $this->assertFalse($constants->isFinal('app\haspattern'));
        $this->assertTrue($constants->isFinal('app\kind'));
        $this->assertFalse($constants->has('app\other'));

        $email = $constants->constant('app\patterns', 'EMAIL');
        $this->assertNotNull($email);
        [$value, $final] = $email;
        $this->assertSame(ValueExpr::LITERAL, $value->kind);
        $this->assertSame('/^[^@]+@[^@]+$/', $value->value);
        $this->assertSame('/p/src/Patterns.php', $value->file);
        $this->assertSame(9, $value->line);
        $this->assertSame(strpos(self::PATTERNS, "'/^[^@]+@"), $value->offset);
        $this->assertSame(26, $value->column);
        $this->assertFalse($final);

        $full = $constants->constant('app\patterns', 'FULL');
        $this->assertNotNull($full);
        $this->assertSame(ValueExpr::CONCAT, $full[0]->kind);
        $this->assertSame('/p/src/Patterns.php', $full[0]->file);
        $this->assertSame(ValueExpr::CLASS_CONSTANT, $full[0]->parts[1]->kind);
        $this->assertSame('app\patterns', $full[0]->parts[1]->class);

        $list = $constants->constant('app\patterns', 'LIST');
        $this->assertNotNull($list);
        $this->assertSame(ValueExpr::ARRAY, $list[0]->kind);
        $this->assertSame('/p/src/Patterns.php', $list[0]->items[0][1]->file);

        $this->assertSame(ValueExpr::NON_STRING, $constants->constant('app\patterns', 'SIZE')[0]->kind ?? null);
        $this->assertSame(ValueExpr::CLASS_CONSTANT, $constants->constant('app\patterns', 'FROM_ELSEWHERE')[0]->kind ?? null, 'A constant of a class the file lacks may be of another file.');
        $this->assertSame('app\support\other', $constants->constant('app\patterns', 'FROM_ELSEWHERE')[0]->class ?? null, 'The class is named through the imports.');
        $this->assertSame(ValueExpr::CONSTANT, $constants->constant('app\patterns', 'GLOBAL')[0]->kind ?? null, 'A global constant is resolved where the value is.');
        $this->assertTrue($constants->constant('app\patterns', 'FIXED')[1] ?? null);
        $this->assertSame('/b/', $constants->constant('app\base', 'RE')[0]->value ?? null);
        $this->assertSame('/i/', $constants->constant('app\haspattern', 'RE')[0]->value ?? null);
        $this->assertSame('/e/', $constants->constant('app\kind', ValueExpr::enumCaseName('Re'))[0]->value ?? null);
        $this->assertNull($constants->constant('app\kind', 'Re'), 'The case itself is no string.');
        $this->assertSame(ValueExpr::CLASS_CONSTANT, $constants->constant('app\kind', 'ALIAS')[0]->kind ?? null);
        $this->assertNull($constants->constant('app\patterns', 'MISSING'));
        $this->assertNull($constants->constant('app\nowhere', 'EMAIL'));
    }

    #[Test]
    public function test_a_literal_on_the_first_line_has_its_column_from_the_start(): void
    {
        $content = "<?php final class A { public const RE = '/x/'; public const LIST = ['/y/']; }";
        $constants = ProjectConstants::of([ProjectConstants::scan($content, 'a.php')]);

        $x = strpos($content, "'/x/'");
        $y = strpos($content, "'/y/'");
        $this->assertNotFalse($x);
        $this->assertNotFalse($y);
        $this->assertSame($x + 1, $constants->constant('a', 'RE')[0]->column ?? null);
        $this->assertSame(1, $constants->constant('a', 'RE')[0]->line ?? null);
        $this->assertSame($y + 1, $constants->constant('a', 'LIST')[0]->items[0][1]->column ?? null);
    }

    #[Test]
    public function test_a_value_the_file_cannot_prove_is_left_out(): void
    {
        $constants = ProjectConstants::of([ProjectConstants::scan(<<<'PHP'
            <?php

            class A
            {
                const CALL = strtoupper('/a/');
                const TERNARY = true ? '/a/' : '/b/';
                const DEEP = ['/a/', [strtoupper('/b/')]];
                const JOINED = '/'.strtoupper('a').'/';
                const FINE = ['/a/', '/b/'];
            }
            PHP, 'a.php')]);

        $this->assertTrue($constants->has('a'));
        $this->assertNull($constants->constant('a', 'CALL'));
        $this->assertNull($constants->constant('a', 'TERNARY'));
        $this->assertNull($constants->constant('a', 'DEEP'));
        $this->assertNull($constants->constant('a', 'JOINED'));
        $this->assertNotNull($constants->constant('a', 'FINE'));
    }

    #[Test]
    public function test_a_class_declared_in_two_files_is_ambiguous_and_gives_nothing(): void
    {
        $one = ProjectConstants::scan("<?php\nnamespace App;\nfinal class Patterns { const RE = '/one/'; }\n", 'one.php');
        $two = ProjectConstants::scan("<?php\nnamespace App;\nclass Patterns { const RE = '/two/'; const OTHER = '/other/'; }\n", 'two.php');
        $constants = ProjectConstants::of([$one, $two]);

        $this->assertTrue($constants->has('app\patterns'));
        $this->assertNull($constants->isFinal('app\patterns'));
        $this->assertNull($constants->constant('app\patterns', 'RE'));
        $this->assertNull($constants->constant('app\patterns', 'OTHER'));
    }

    #[Test]
    public function test_a_project_class_wins_over_a_library_class_of_the_same_name(): void
    {
        $project = ProjectConstants::scan("<?php\nnamespace App;\nfinal class Patterns { const RE = '/project/'; }\n", 'src/Patterns.php');
        $library = ProjectConstants::scan("<?php\nnamespace App;\nfinal class Pat { const RE = '/pat/'; }\nfinal class Patterns { const RE = '/library/'; }\nfinal class Lib { const RE = '/lib/'; }\n", 'vendor/acme/Patterns.php');
        $constants = ProjectConstants::of([$project], [$library]);

        $this->assertSame('/project/', $constants->constant('app\patterns', 'RE')[0]->value ?? null);
        $this->assertSame('/pat/', $constants->constant('app\pat', 'RE')[0]->value ?? null, 'A library class named as a prefix of a project class brings its own constants only.');
        $this->assertSame('/lib/', $constants->constant('app\lib', 'RE')[0]->value ?? null);
    }

    #[Test]
    public function test_a_file_is_read_from_the_disk_and_an_unreadable_one_contributes_nothing(): void
    {
        $project = $this->makeProject(['src/Patterns.php' => self::PATTERNS]);

        $contribution = ProjectConstants::scanFile($project.'/src/Patterns.php');
        $this->assertSame('/^[^@]+@[^@]+$/', ProjectConstants::of([$contribution])->constant('app\patterns', 'EMAIL')[0]->value ?? null);
        $this->assertSame($project.'/src/Patterns.php', ProjectConstants::of([$contribution])->constant('app\patterns', 'EMAIL')[0]->file ?? null);

        $this->assertSame(ProjectConstants::nothing(), ProjectConstants::scanFile($project.'/src/Missing.php'));
    }

    #[Test]
    public function test_a_text_too_large_for_the_memory_left_contributes_nothing(): void
    {
        $huge = "<?php\nfinal class Huge { public const RE = '/h/'; }\n".str_repeat("// padding\n", 300_000);
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * 1024 * 1024));

        try {
            $contribution = ProjectConstants::scan($huge, 'huge.php');
        } finally {
            ini_set('memory_limit', \is_string($limit) ? $limit : '-1');
        }

        $this->assertSame(ProjectConstants::nothing(), $contribution);
    }

    #[Test]
    public function test_a_contribution_survives_a_round_trip_through_serialization(): void
    {
        $contribution = ProjectConstants::scan(self::PATTERNS, 'Patterns.php');

        $this->assertEquals($contribution, unserialize(serialize($contribution), ['allowed_classes' => [ValueExpr::class]]));
    }

    #[Test]
    public function test_none_knows_nothing(): void
    {
        $this->assertFalse(ProjectConstants::none()->has('a'));
        $this->assertNull(ProjectConstants::none()->constant('a', 'RE'));
    }
}
