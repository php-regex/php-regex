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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Parser\Printer\FormatRefusal;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Format from PlaygroundDriver
 * @phpstan-import-type Redos from PlaygroundDriver
 * @phpstan-import-type Transpile from PlaygroundDriver
 * @phpstan-import-type Samples from PlaygroundDriver
 * @phpstan-import-type Checks from PlaygroundDriver
 * @phpstan-import-type Export from PlaygroundDriver
 *
 * The playground's tools: what the console does with a pattern, answered by
 * the driver for the page's Tools panel — a layout, a ReDoS verdict, a
 * translation, sample subjects, a test to paste. Every string the driver
 * says matches or does not match went through preg_match() in the driver
 * first; every row here checks that against the engine once more. Every
 * row runs on the tree's driver over src/ and on the bundled driver.
 */
#[Group('documentation')]
final class PlaygroundToolsTest extends TestCase
{
    private const DATE = '/^(?<year>\d{4})-(?<month>\d{2})$/';
    private const CATASTROPHIC = '/^(a+)+$/';

    /**
     * The console's own answer for the pattern above at width 24,
     * captured on 2026-11-03: `bin/regex format <pattern> --width=24`.
     */
    private const DATE_LAID_OUT = "/\n    ^\n    (?<year>\\d{4})\n    -\n    (?<month>\\d{2})\n    $\n/x";

    /**
     * @return iterable<string, array{driver: string, root: string}>
     */
    public static function provideDrivers(): iterable
    {
        yield from PlaygroundDriver::drivers();
    }

    // --- format --------------------------------------------------------------

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_format_lays_out_at_the_width(string $driver, string $root): void
    {
        $payload = self::format($driver, $root, self::DATE, ['width' => 24]);

        $this->assertSame(2, $payload['protocol']);
        $this->assertNull($payload['error']);
        $this->assertNotNull($payload['format']);
        $this->assertSame(self::DATE_LAID_OUT, $payload['format']['pattern']);
        $this->assertTrue($payload['format']['changed']);
        $this->assertNull($payload['format']['refusal']);
        $this->assertIsFloat($payload['timing']['formatMs']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_format_returns_a_fitting_pattern_unchanged(string $driver, string $root): void
    {
        $payload = self::format($driver, $root, '/a+/', ['width' => 80]);

        $this->assertNotNull($payload['format']);
        $this->assertSame('/a+/', $payload['format']['pattern']);
        $this->assertFalse($payload['format']['changed']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_compact_writes_one_line(string $driver, string $root): void
    {
        $payload = self::format($driver, $root, self::DATE_LAID_OUT, ['compact' => true]);

        $this->assertNotNull($payload['format']);
        $this->assertSame(self::DATE, $payload['format']['pattern']);
        $this->assertTrue($payload['format']['changed']);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideLayoutPreservesTheLanguage(): iterable
    {
        yield 'the date' => ['pattern' => self::DATE, 'subjects' => ['2026-11', '1999-1', 'x']];
        yield 'e-mail addresses' => ['pattern' => '/[\w.+-]+@[\w-]+\.[\w.-]+/i', 'subjects' => ['Write to hello@php-regex.com or to noreply@example.org.', 'no address', '@']];
        yield 'catastrophic' => ['pattern' => self::CATASTROPHIC, 'subjects' => ['aaa', 'aab', '']];
        yield 'unicode' => ['pattern' => '/\p{Lu}\p{Ll}+/u', 'subjects' => ['Ünïcode Straße Ωmega', 'lower', '']];
        yield 'already x with a comment' => ['pattern' => "/ a # the letter\n b /x", 'subjects' => ['ab', 'a b', 'b']];
        yield 'a hash inside a class' => ['pattern' => '/[#]+x/', 'subjects' => ['##x', 'x', '#']];
        yield 'dollar end only' => ['pattern' => '/a$/D', 'subjects' => ["a\n", 'a', 'b']];
        yield 'a comment group' => ['pattern' => '/a(?#note)b/', 'subjects' => ['ab', 'a b']];
        yield 'a quoted run' => ['pattern' => '/\Q.*\E+x/', 'subjects' => ['.*x', 'ax', '.*.*x']];
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideLayoutPreservesTheLanguage')]
    public function test_format_keeps_the_language(string $pattern, array $subjects): void
    {
        $bundle = PlaygroundDriver::extractBundle();
        $payload = self::format($bundle['driver'], $bundle['root'], $pattern, ['width' => 12]);

        $this->assertNull($payload['error'], (string) json_encode($payload));
        $this->assertNotNull($payload['format']);
        if (null !== $payload['format']['refusal']) {
            $this->assertSame($pattern, $payload['format']['pattern'], 'A refused pattern comes back as written.');

            return;
        }
        foreach ($subjects as $subject) {
            $before = preg_match_all($pattern, $subject, $expected, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            $after = preg_match_all($payload['format']['pattern'], $subject, $actual, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            $this->assertSame($before, $after, $pattern.' on '.json_encode($subject));
            $this->assertSame($expected, $actual, $pattern.' on '.json_encode($subject));
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_format_reports_a_refusal_with_a_sentence(string $driver, string $root): void
    {
        // A quantifier spelled with spaces is a quantifier from PCRE2 10.43
        // on; under x the spaces would vanish and the pattern would change.
        $payload = self::format($driver, $root, '/a{ 1,2 }b/', ['width' => 4]);

        $this->assertNotNull($payload['format']);
        $this->assertSame('/a{ 1,2 }b/', $payload['format']['pattern']);
        $this->assertFalse($payload['format']['changed']);
        $this->assertNotNull($payload['format']['refusal']);
        $this->assertSame(FormatRefusal::SpacedQuantifier->value, $payload['format']['refusal']['code']);
        $this->assertNotSame('', $payload['format']['refusal']['message']);
    }

    #[Test]
    public function test_every_refusal_has_a_sentence(): void
    {
        $source = (string) file_get_contents(PlaygroundDriver::TREE_DRIVER);

        foreach (FormatRefusal::cases() as $case) {
            $this->assertStringContainsString("'".$case->value."' =>", $source, 'The driver has no sentence for the refusal '.$case->name.'.');
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_format_refuses_an_unparsable_pattern_and_a_bad_width(string $driver, string $root): void
    {
        $broken = self::format($driver, $root, '/(a/', []);
        $this->assertNull($broken['format']);
        $this->assertNotNull($broken['error']);
        $this->assertSame('parse', $broken['error']['source']);
        $this->assertSame(2, $broken['error']['position']);

        $width = self::format($driver, $root, '/a/', ['width' => 0]);
        $this->assertNull($width['format']);
        $this->assertNotNull($width['error']);
        $this->assertSame('format', $width['error']['source']);
        $this->assertStringContainsString('width', $width['error']['message']);
    }

    // --- redos ---------------------------------------------------------------

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_redos_theoretical_carries_the_verdict_the_witness_and_the_attack(string $driver, string $root): void
    {
        $payload = self::redos($driver, $root, self::CATASTROPHIC);

        $this->assertSame(2, $payload['protocol']);
        $this->assertNull($payload['error']);
        $redos = $payload['redos'];
        $this->assertNotNull($redos);
        $this->assertSame('critical', $redos['severity']);
        $this->assertSame('exponential', $redos['complexity']);
        $this->assertSame('theoretical', $redos['mode']);
        $this->assertFalse($redos['confirmed']);
        $this->assertSame([[1, 6, 'medium', '(a+)+'], [2, 4, 'critical', 'a+']], array_map(static fn (array $h): array => [$h['start'], $h['end'], $h['severity'], $h['pattern']], $redos['hotspots']));
        $this->assertSame(['prefix' => '', 'pump' => 'a', 'suffix' => '!'], $redos['witness']);
        $this->assertFalse($redos['linear']);
        $this->assertSame(str_repeat('a', 30).'!', $redos['attack'], 'The attack string is the witness built raw, never re-assembled from the escaped spelling.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_redos_confirmed_replays_under_the_library_caps(string $driver, string $root): void
    {
        $payload = self::redos($driver, $root, self::CATASTROPHIC, ['mode' => 'confirmed']);

        $redos = $payload['redos'];
        $this->assertNotNull($redos);
        $this->assertSame('confirmed', $redos['mode']);
        $this->assertTrue($redos['confirmed']);
        $this->assertNotNull($redos['confirmation']);
        $this->assertSame('backtrack_limit', $redos['confirmation']['evidence']);
        $this->assertSame(100_000, $redos['confirmation']['backtrack_limit']);
        $this->assertNull($redos['replay_error']);
        $this->assertSame(str_repeat('a', 16).'!', $redos['attack'], 'The attack string is the sample that tripped the limit, 17 bytes.');
    }

    /**
     * @return iterable<string, array{pattern: string, severity: string, linear: bool, witness: bool}>
     */
    public static function provideVerdicts(): iterable
    {
        yield 'digits' => ['pattern' => '/^\d+$/', 'severity' => 'safe', 'linear' => true, 'witness' => false];
        yield 'the date' => ['pattern' => '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/m', 'severity' => 'safe', 'linear' => true, 'witness' => false];
        yield 'e-mail: linear attempt, costly search' => ['pattern' => '/[\w.+-]+@[\w-]+\.[\w.-]+/i', 'severity' => 'safe', 'linear' => false, 'witness' => false];
        yield 'unicode' => ['pattern' => '/\p{Lu}\p{Ll}+/u', 'severity' => 'safe', 'linear' => true, 'witness' => false];
        yield 'catastrophic' => ['pattern' => self::CATASTROPHIC, 'severity' => 'critical', 'linear' => false, 'witness' => true];
    }

    #[Test]
    #[DataProvider('provideVerdicts')]
    public function test_redos_verdicts_on_the_try_row(string $pattern, string $severity, bool $linear, bool $witness): void
    {
        $bundle = PlaygroundDriver::extractBundle();
        $payload = self::redos($bundle['driver'], $bundle['root'], $pattern);

        $this->assertNotNull($payload['redos']);
        $this->assertSame($severity, $payload['redos']['severity']);
        $this->assertSame($linear, $payload['redos']['linear'], 'linear = proven safe and no search cost, the regex-phpunit rule.');
        $this->assertSame($witness, null !== $payload['redos']['witness']);
        $this->assertSame($witness, null !== $payload['redos']['attack']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_the_attack_string_exhausts_the_engine_without_jit(string $driver, string $root): void
    {
        $attack = self::redos($driver, $root, self::CATASTROPHIC)['redos']['attack'] ?? null;
        $this->assertIsString($attack);

        $matched = PlaygroundDriver::match($driver, $root, self::CATASTROPHIC, $attack, ['pcre.jit=0']);
        $this->assertNotNull($matched['matches']);
        $this->assertSame('Backtrack limit exhausted', $matched['matches']['error']);

        $short = PlaygroundDriver::match($driver, $root, self::CATASTROPHIC, str_repeat('a', 15).'!', ['pcre.jit=0']);
        $this->assertNotNull($short['matches']);
        $this->assertSame('No error', $short['matches']['error']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_redos_on_an_unparsable_pattern_is_the_library_own_verdict(string $driver, string $root): void
    {
        $payload = self::redos($driver, $root, '/(a/');

        $this->assertNull($payload['error']);
        $this->assertNotNull($payload['redos']);
        $this->assertSame('unknown', $payload['redos']['severity']);
        $this->assertIsString($payload['redos']['error']);
        $this->assertSame([], $payload['redos']['hotspots']);
        $this->assertNull($payload['redos']['attack']);
    }

    // --- transpile -----------------------------------------------------------

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_transpile_to_each_target(string $driver, string $root): void
    {
        $js = self::transpile($driver, $root, '/^a$/m', 'js');
        $this->assertSame(2, $js['protocol']);
        $this->assertNull($js['refusal']);
        $this->assertNotNull($js['transpile']);
        $this->assertSame('javascript', $js['transpile']['target']);
        $this->assertSame('(?:^|(?<=\n)(?!$))a(?=\n|$)', $js['transpile']['pattern']);
        $this->assertSame('/(?:^|(?<=\n)(?!$))a(?=\n|$)/', $js['transpile']['literal']);
        $this->assertSame([], $js['transpile']['warnings']);
        $this->assertCount(1, $js['transpile']['notes']);
        $this->assertStringStartsWith('Applied /m', $js['transpile']['notes'][0]);

        $html = self::transpile($driver, $root, '/^[a-z]+$/i', 'html');
        $this->assertNotNull($html['transpile']);
        $this->assertSame('^[a-zA-Z]+$', $html['transpile']['pattern']);
        $this->assertSame('v', $html['transpile']['flags']);

        $python = self::transpile($driver, $root, '/(?>a+)b/', 'python', ['pythonVersion' => '3.11']);
        $this->assertNotNull($python['transpile']);
        $this->assertSame("r'(?a)(?>a+)b'", $python['transpile']['literal']);
        $this->assertStringContainsString('re.ASCII', (string) $python['transpile']['notes'][0]);
    }

    /**
     * @return iterable<string, array{pattern: string, target: string, fields: array<string, string>, message: string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'atomic group to JavaScript' => ['pattern' => '/(?>a+)b/', 'target' => 'js', 'fields' => [], 'message' => 'Atomic groups are not supported in JavaScript.'];
        yield 'a verb to JavaScript' => ['pattern' => '/a(*SKIP)b/', 'target' => 'js', 'fields' => [], 'message' => 'PCRE verbs are not supported in JavaScript.'];
        yield '\K to JavaScript' => ['pattern' => '/(?<=a)b\K/', 'target' => 'js', 'fields' => [], 'message' => '\K is not supported in JavaScript.'];
        yield 'atomic group to Python 3.10' => ['pattern' => '/(?>a+)b/', 'target' => 'python', 'fields' => ['pythonVersion' => '3.10'], 'message' => 'Atomic groups are not supported in Python re.'];
        yield 'an unknown target' => ['pattern' => '/a/', 'target' => 'ruby', 'fields' => [], 'message' => 'Unknown transpile target: ruby. Expected one of: html, html-pattern, javascript, js, py, python.'];
    }

    /**
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_transpile_reports_a_refusal_verbatim(string $pattern, string $target, array $fields, string $message): void
    {
        $bundle = PlaygroundDriver::extractBundle();
        $payload = self::transpile($bundle['driver'], $bundle['root'], $pattern, $target, $fields);

        $this->assertNull($payload['error']);
        $this->assertNull($payload['transpile']);
        $this->assertSame(['message' => $message], $payload['refusal']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_transpile_refuses_an_unparsable_pattern_and_a_bad_python_version(string $driver, string $root): void
    {
        $broken = self::transpile($driver, $root, '/(a/', 'js');
        $this->assertNull($broken['transpile']);
        $this->assertNotNull($broken['error']);
        $this->assertSame('parse', $broken['error']['source']);

        $version = self::transpile($driver, $root, '/a/', 'python', ['pythonVersion' => 'latest']);
        $this->assertNull($version['transpile']);
        $this->assertNotNull($version['error']);
        $this->assertSame('transpile', $version['error']['source']);
        $this->assertStringContainsString('pythonVersion', $version['error']['message']);
    }

    // --- samples -------------------------------------------------------------

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideTryRow(): iterable
    {
        yield 'the date' => ['pattern' => '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/m'];
        yield 'e-mail' => ['pattern' => '/[\w.+-]+@[\w-]+\.[\w.-]+/i'];
        yield 'catastrophic' => ['pattern' => self::CATASTROPHIC];
        yield 'unicode' => ['pattern' => '/\p{Lu}\p{Ll}+/u'];
    }

    #[Test]
    #[DataProvider('provideTryRow')]
    public function test_samples_are_checked_by_the_engine(string $pattern): void
    {
        $bundle = PlaygroundDriver::extractBundle();
        $payload = self::samples($bundle['driver'], $bundle['root'], $pattern);

        $this->assertSame(2, $payload['protocol']);
        $this->assertNull($payload['error']);
        $samples = $payload['samples'];
        $this->assertNotNull($samples);
        $this->assertNotSame([], $samples['matching']);
        $this->assertNotSame([], $samples['nonMatching']);
        $this->assertLessThanOrEqual(3, \count($samples['matching']));
        $this->assertLessThanOrEqual(3, \count($samples['nonMatching']));
        $this->assertSame($samples['matching'], array_values(array_unique($samples['matching'])));
        $this->assertIsInt($samples['seed']);
        $this->assertSame(0, $samples['dropped'], 'Both generators check their strings against the engine already.');
        ini_set('pcre.jit', '0');

        try {
            foreach ($samples['matching'] as $sample) {
                $this->assertSame(1, preg_match($pattern, $sample), json_encode($sample).' was said to match.');
            }
            foreach ($samples['nonMatching'] as $sample) {
                $this->assertSame(0, preg_match($pattern, $sample), json_encode($sample).' was said not to match.');
                $this->assertSame(0, preg_match('/[\x00-\x08\x0B-\x1F]/', $sample), 'A control byte makes no chip.');
            }
        } finally {
            ini_restore('pcre.jit');
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_samples_and_the_attack_are_text_the_page_can_carry(string $driver, string $root): void
    {
        // A byte-mode pattern draws bytes; a page carrying UTF-16 text could
        // not send them back as they are, so none becomes a chip.
        $samples = self::samples($driver, $root, '/[\x80-\xff]+x/')['samples'];
        $this->assertNotNull($samples);
        foreach (array_merge($samples['matching'], $samples['nonMatching']) as $sample) {
            $this->assertTrue(mb_check_encoding($sample, 'UTF-8'), bin2hex($sample).' is not text.');
        }
        foreach ($samples['matching'] as $sample) {
            $this->assertSame(1, preg_match('/[\x80-\xff]+x/', $sample), 'A kept sample is one the engine matches, as a valid UTF-8 sequence of bytes above 0x7F can be.');
        }

        $attack = self::redos($driver, $root, '/^([\x80-\xff]+)+$/')['redos']['attack'] ?? null;
        $this->assertTrue(null === $attack || mb_check_encoding($attack, 'UTF-8'), 'An attack string of raw bytes is withheld, never substituted.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_samples_are_stable_for_a_pattern(string $driver, string $root): void
    {
        $first = self::samples($driver, $root, self::DATE);
        $second = self::samples($driver, $root, self::DATE);

        $this->assertSame($first['samples'], $second['samples']);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_samples_for_a_pattern_that_matches_nothing(string $driver, string $root): void
    {
        $payload = self::samples($driver, $root, '/(?!)/');

        $this->assertNull($payload['error']);
        $this->assertNotNull($payload['samples']);
        $this->assertSame([], $payload['samples']['matching']);
        $this->assertSame([''], $payload['samples']['nonMatching'], 'The empty string is a chip; the NUL byte is not.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_samples_refuse_an_unparsable_pattern(string $driver, string $root): void
    {
        $payload = self::samples($driver, $root, '/(a/');

        $this->assertNull($payload['samples']);
        $this->assertNotNull($payload['error']);
        $this->assertSame('parse', $payload['error']['source']);
    }

    // --- check ---------------------------------------------------------------

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_check_is_the_engine_alone_without_jit(string $driver, string $root): void
    {
        $payload = self::check($driver, $root.'/does-not-exist', '/(?<a>x)(y)?(z)/', ['xz', 'q', str_repeat('a', 30)]);

        $this->assertSame(2, $payload['protocol']);
        $this->assertSame(\PHP_VERSION, $payload['engine']['php']);
        $this->assertCount(3, $payload['checks']);
        $this->assertSame(['matched' => 1, 'error' => null, 'groups' => ['0' => ['xz', 0], 'a' => ['x', 0], '1' => ['x', 0], '2' => null, '3' => ['z', 1]]], $payload['checks'][0]);
        $this->assertSame(['matched' => 0, 'error' => null, 'groups' => null], $payload['checks'][1]);

        $hostile = self::check($driver, $root.'/does-not-exist', self::CATASTROPHIC, [str_repeat('a', 30).'!'], ['pcre.jit=1']);
        $this->assertSame(['matched' => null, 'error' => 'Backtrack limit exhausted', 'groups' => null], $hostile['checks'][0], 'The check runs the engine without its JIT whatever the ini says: the exported test will.');

        $broken = self::check($driver, $root.'/does-not-exist', '/(/', ['a']);
        $this->assertNull($broken['checks'][0]['matched']);
        $this->assertStringStartsWith('Compilation failed', (string) $broken['checks'][0]['error']);
    }

    // --- export --------------------------------------------------------------

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_asserts_what_the_engine_answered(string $driver, string $root): void
    {
        $payload = self::export($driver, $root, '/(?<alpha>a+)(b+)?(?<gamma>c)/', ['aac', 'xyz']);

        $this->assertSame(2, $payload['protocol']);
        $this->assertNull($payload['error']);
        $export = $payload['export'];
        $this->assertNotNull($export);
        $this->assertSame(3, $export['assertions'], 'One per subject, plus the compile assertion.');
        $this->assertSame(0, $export['refused']);
        $this->assertIsBool($export['linear']);
        $method = $export['method'];
        $this->assertStringContainsString("'alpha' => ['aa', 0]", $method, 'A named group is asserted by name.');
        $this->assertStringContainsString('2 => false', $method, 'A group that did not participate is asserted as false.');
        $this->assertStringContainsString("'gamma' => ['c', 2]", $method);
        $this->assertStringNotContainsString('0 =>', $method, 'Group 0 is the match itself.');
        $this->assertStringContainsString("'subject' => 'xyz', 'matches' => false", $method);
        $this->assertStringContainsString('assertPatternDoesNotMatch', $method);
        $this->assertStringContainsString('assertPatternCompiles', $method);
        $this->assertStringContainsString('#[DataProvider(', $method);
        $this->assertStringContainsString('use PHPRegex\PHPUnit\RegexAssertions;', $export['class']);
        $this->assertStringContainsString('final class PatternTest extends TestCase', $export['class']);
        $this->assertStringContainsString($method, $export['class'], 'The class wraps the very same body.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_asserts_linear_only_when_proven(string $driver, string $root): void
    {
        $this->assertStringNotContainsString('assertPatternIsLinear', self::export($driver, $root, self::CATASTROPHIC, ['aaa'])['export']['method'] ?? '');
        $this->assertStringNotContainsString('assertPatternIsLinear', self::export($driver, $root, '/[\w.+-]+@[\w-]+\.[\w.-]+/i', ['a@b.c'])['export']['method'] ?? '', 'A linear attempt with a costly search is not "linear" to regex-phpunit.');
        $this->assertStringContainsString('assertPatternIsLinear', self::export($driver, $root, '/^\d+$/', ['42'])['export']['method'] ?? '');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_under_n_asserts_by_name_only(string $driver, string $root): void
    {
        $method = self::export($driver, $root, '/(?<a>x)(y)?(z)/n', ['xz'])['export']['method'] ?? '';

        $this->assertStringContainsString("'a' => ['x', 0]", $method);
        $this->assertStringNotContainsString('1 =>', $method, 'Under n, the number is the renumbered name: asserted by name alone.');
        $this->assertStringNotContainsString('2 =>', $method);
        $this->assertStringNotContainsString('3 =>', $method, 'Under n, an unnamed group does not capture.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_under_j_asserts_duplicates_by_number(string $driver, string $root): void
    {
        $method = self::export($driver, $root, '/(?J)(?<n>a)|(?<n>b)/', ['b'])['export']['method'] ?? '';

        $this->assertStringNotContainsString("'n' =>", $method, 'A name owning two numbers is never asserted by name.');
        $this->assertStringContainsString('1 => false', $method);
        $this->assertStringContainsString("2 => ['b', 0]", $method);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_comments_a_subject_the_engine_refuses(string $driver, string $root): void
    {
        // The refused subject holds a line break and a PHP close tag: raw,
        // either would end the comment it is written in.
        $refused = str_repeat('a', 30)."!\n?> and more";
        $payload = self::export($driver, $root, self::CATASTROPHIC, ['aaa', $refused]);

        $export = $payload['export'];
        $this->assertNotNull($export);
        $this->assertSame(1, $export['refused']);
        $this->assertSame(2, $export['assertions']);
        $this->assertStringContainsString("// '".str_repeat('a', 30)."!\\n?\\> and more': the engine answered \"Backtrack limit exhausted\"; nothing to assert", $export['method']);
        $this->assertStringNotContainsString("\n?>", $export['class']);

        // The class still parses: php -l on what the page would copy.
        $file = tempnam(sys_get_temp_dir(), 'phpregex-export-').'.php';
        file_put_contents($file, $export['class']);

        try {
            exec(escapeshellarg(\PHP_BINARY).' -d auto_prepend_file= -l '.escapeshellarg($file).' 2>&1', $lines, $exit);
            $this->assertSame(0, $exit, implode("\n", $lines));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_takes_the_checks_of_another_engine(string $driver, string $root): void
    {
        // The page may hand the engine's answers over (the 8.0 instance
        // matched, the 8.4 one writes): the export then runs no match of
        // its own and asserts what it was given.
        $checks = [['matched' => 1, 'error' => null, 'groups' => ['0' => ['ab', 0], '1' => ['b', 1]]]];
        $payload = self::export($driver, $root, '/a(b)/', ['ab'], ['checks' => $checks]);

        $this->assertStringContainsString("1 => ['b', 1]", $payload['export']['method'] ?? '');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_refuses_an_unparsable_pattern_a_truncated_subject_and_too_many(string $driver, string $root): void
    {
        $broken = self::export($driver, $root, '/(a/', ['a']);
        $this->assertNull($broken['export']);
        $this->assertSame('parse', $broken['error']['source'] ?? null);

        $long = self::export($driver, $root, '/a/', [str_repeat('a', 10001)]);
        $this->assertNull($long['export']);
        $this->assertSame('export', $long['error']['source'] ?? null);
        $this->assertStringContainsString('10000', (string) ($long['error']['message'] ?? ''));

        $many = self::export($driver, $root, '/a/', array_fill(0, 51, 'a'));
        $this->assertNull($many['export']);
        $this->assertSame('export', $many['error']['source'] ?? null);
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_export_escapes_through_var_export(string $driver, string $root): void
    {
        $subject = "it's \\ a \"quote\"\nand a \x00 byte";
        $payload = self::export($driver, $root, '/it/', [$subject]);

        $method = $payload['export']['method'] ?? '';
        $this->assertStringContainsString(var_export($subject, true), $method);
    }

    /**
     * The proof the export is true: the class the driver writes runs under
     * this repository's PHPUnit, with regex-phpunit autoloaded, and passes.
     */
    #[Test]
    public function test_the_exported_class_passes_under_phpunit(): void
    {
        $bundle = PlaygroundDriver::extractBundle();
        $subjects = ['2026-11-02 and 1999-12-31', 'not a date', "it's \"quoted\"\n", str_repeat('a', 30).'!'];
        $payload = self::export($bundle['driver'], $bundle['root'], '/(?<year>\d{4})-(\d{2})-(?<day>\d{2})(x)?/', $subjects);
        $this->assertNotNull($payload['export'], (string) json_encode($payload['error']));

        $dir = sys_get_temp_dir().'/phpregex-playground-export-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $file = $dir.'/PatternTest.php';
        file_put_contents($file, $payload['export']['class']);

        try {
            $command = [
                \PHP_BINARY, '-d', 'auto_prepend_file=',
                PlaygroundDriver::ROOT.'/tools/phpunit/vendor/bin/phpunit',
                '--no-configuration', '--do-not-cache-result', '--no-output', '--log-junit', $dir.'/junit.xml',
                '--bootstrap', PlaygroundDriver::ROOT.'/vendor/autoload.php',
                $file,
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $junit = (string) @file_get_contents($dir.'/junit.xml');
            $this->assertSame(0, $exit, "The exported test failed.\n".$stdout.$stderr."\n".$junit."\n".$payload['export']['class']);
            $this->assertSame(1, preg_match('/<testsuite [^>]*\btests="(\d+)"/', $junit, $tests), $junit);
            $this->assertSame(1, preg_match('/<testsuite [^>]*\bfailures="(\d+)"/', $junit, $failures));
            $this->assertSame(1, preg_match('/<testsuite [^>]*\berrors="(\d+)"/', $junit, $errors));
            $this->assertGreaterThanOrEqual(5, (int) $tests[1], 'Four subject rows plus the compile test.');
            $this->assertSame('0', $failures[1]);
            $this->assertSame('0', $errors[1]);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $leftover) {
                unlink($leftover);
            }
            rmdir($dir);
        }
    }

    // --- every op --------------------------------------------------------------

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_every_tool_refuses_a_pattern_over_the_cap(string $driver, string $root): void
    {
        $regex = '/'.str_repeat('a', 2000).'/';

        foreach ([
            ['op' => 'format', 'regex' => $regex],
            ['op' => 'transpile', 'regex' => $regex, 'target' => 'js'],
            ['op' => 'samples', 'regex' => $regex],
            ['op' => 'export', 'regex' => $regex, 'subjects' => ['a']],
        ] as $input) {
            $payload = PlaygroundDriver::ask($driver, $root, $input);
            $this->assertArrayNotHasKey('fatal', $payload, $input['op']);
            $error = $payload['error'];
            $this->assertIsArray($error, $input['op']);
            $this->assertIsString($error['message']);
            $this->assertStringContainsString('2000', (string) $error['message'], $input['op']);
        }
        $redos = PlaygroundDriver::ask($driver, $root, ['op' => 'redos', 'regex' => $regex]);
        $this->assertArrayNotHasKey('fatal', $redos);
        $verdict = $redos['redos'];
        $this->assertIsArray($verdict);
        $this->assertSame('unknown', $verdict['severity'], 'The library judges a pattern over its cap with its own verdict.');

        $check = PlaygroundDriver::ask($driver, $root.'/does-not-exist', ['op' => 'check', 'regex' => $regex, 'subjects' => ['a']]);
        $this->assertSame([], $check['checks'], 'The engine is not asked for a pattern past the cap.');
    }

    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_every_tool_answers_in_the_envelope_with_protocol_two(string $driver, string $root): void
    {
        foreach ([
            ['op' => 'format', 'regex' => self::DATE, 'width' => 24],
            ['op' => 'format', 'regex' => '/(/'],
            ['op' => 'redos', 'regex' => self::CATASTROPHIC],
            ['op' => 'redos', 'regex' => '/(/', 'mode' => 'confirmed'],
            ['op' => 'transpile', 'regex' => '/a/', 'target' => 'js'],
            ['op' => 'transpile', 'regex' => '/a/'],
            ['op' => 'samples', 'regex' => '/a/'],
            ['op' => 'check', 'regex' => '/a/', 'subjects' => ['a']],
            ['op' => 'check', 'regex' => '/a/'],
            ['op' => 'export', 'regex' => '/a/', 'subjects' => ['a']],
            ['op' => 'export', 'regex' => '/a/', 'subjects' => 'a'],
        ] as $input) {
            $run = PlaygroundDriver::run($driver, $root, $input);
            $label = json_encode($input, \JSON_THROW_ON_ERROR);
            $this->assertSame(0, $run['exit'], $label.': '.$run['stderr']);
            $this->assertSame('', $run['stderr'], $label);
            $this->assertMatchesRegularExpression('/\A\x1e\{.*\}\x1e\z/s', $run['stdout'], $label);
            $payload = PlaygroundDriver::payload($run);
            $this->assertSame(2, $payload['protocol'], $label);
            $this->assertArrayNotHasKey('fatal', $payload, $label);
            $this->assertSame([], $payload['notices'], $label.': the library raised a notice on this engine.');
        }
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     *
     * @return Format
     */
    private static function format(string $driver, string $root, string $regex, array $fields): array
    {
        /** @var Format $answer */
        $answer = PlaygroundDriver::tool($driver, $root, 'format', $regex, $fields);

        return $answer;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return Redos
     */
    private static function redos(string $driver, string $root, string $regex, array $fields = []): array
    {
        /** @var Redos $answer */
        $answer = PlaygroundDriver::tool($driver, $root, 'redos', $regex, $fields);

        return $answer;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return Transpile
     */
    private static function transpile(string $driver, string $root, string $regex, string $target, array $fields = []): array
    {
        /** @var Transpile $answer */
        $answer = PlaygroundDriver::tool($driver, $root, 'transpile', $regex, ['target' => $target] + $fields);

        return $answer;
    }

    /**
     * @return Samples
     */
    private static function samples(string $driver, string $root, string $regex): array
    {
        /** @var Samples $answer */
        $answer = PlaygroundDriver::tool($driver, $root, 'samples', $regex);

        return $answer;
    }

    /**
     * @param list<string> $subjects
     * @param list<string> $ini
     *
     * @return Checks
     */
    private static function check(string $driver, string $root, string $regex, array $subjects, array $ini = []): array
    {
        /** @var Checks $answer */
        $answer = PlaygroundDriver::tool($driver, $root, 'check', $regex, ['subjects' => $subjects], null, $ini);

        return $answer;
    }

    /**
     * @param list<string>         $subjects
     * @param array<string, mixed> $fields
     *
     * @return Export
     */
    private static function export(string $driver, string $root, string $regex, array $subjects, array $fields = []): array
    {
        /** @var Export $answer */
        $answer = PlaygroundDriver::tool($driver, $root, 'export', $regex, ['subjects' => $subjects] + $fields);

        return $answer;
    }
}
