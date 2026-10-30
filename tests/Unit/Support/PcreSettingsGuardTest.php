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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Tests\Support\PcreSettingsGuard;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PCRE settings are set for the whole process: a test that leaves a
 * limit lowered, the JIT off or the library's ini setter installed fails
 * the run, named, and the next test finds the settings as they were.
 */
final class PcreSettingsGuardTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (PcreSettingsGuard::SETTINGS as $key) {
            $this->saved[$key] = ini_get($key);
        }
    }

    protected function tearDown(): void
    {
        LibraryPcre::useIniSetter(null);
        foreach ($this->saved as $key => $value) {
            if (false !== $value) {
                ini_set($key, $value);
            }
        }
    }

    #[Test]
    public function test_restore_reports_nothing_when_the_test_changed_nothing(): void
    {
        $guard = new PcreSettingsGuard();
        $guard->snapshot();

        $this->assertSame([], $guard->restore());
    }

    #[Test]
    public function test_restore_reports_nothing_without_a_snapshot(): void
    {
        $guard = new PcreSettingsGuard();
        $guard->snapshot();
        $guard->restore();
        ini_set('pcre.backtrack_limit', '19');

        $this->assertSame([], $guard->restore());
        $this->assertSame('19', ini_get('pcre.backtrack_limit'));
    }

    #[Test]
    #[DataProvider('provideLeftSettings')]
    public function test_restore_names_and_puts_back_a_setting_left_changed(string $key, string $value): void
    {
        $guard = new PcreSettingsGuard();
        $found = (string) ini_get($key);
        $guard->snapshot();
        ini_set($key, $value);

        $changed = $guard->restore();

        $this->assertSame([$key => ['"'.$found.'"', '"'.$value.'"']], $changed);
        $this->assertSame($found, ini_get($key));
    }

    /**
     * @return iterable<string, array{key: string, value: string}>
     */
    public static function provideLeftSettings(): iterable
    {
        yield 'a lowered backtrack limit' => ['key' => 'pcre.backtrack_limit', 'value' => '19'];
        yield 'a lowered recursion limit' => ['key' => 'pcre.recursion_limit', 'value' => '2'];
        yield 'the JIT turned the other way' => ['key' => 'pcre.jit', 'value' => '1' === ini_get('pcre.jit') ? '0' : '1'];
    }

    #[Test]
    public function test_restore_names_and_removes_an_ini_setter_left_installed(): void
    {
        $guard = new PcreSettingsGuard();
        $guard->snapshot();
        LibraryPcre::useIniSetter(static fn (): bool => false);

        $changed = $guard->restore();

        $this->assertSame(['LibraryPcre::useIniSetter()' => ['ini_set', 'a closure']], $changed);
        $this->assertNull((new \ReflectionClass(LibraryPcre::class))->getStaticPropertyValue('iniSetter'));
    }

    /**
     * A window of the library's limits left open makes the library take
     * every later call for one made inside it, raising nothing.
     */
    #[Test]
    public function test_restore_names_and_closes_a_window_left_open(): void
    {
        $guard = new PcreSettingsGuard();
        $guard->snapshot();
        $library = new \ReflectionClass(LibraryPcre::class);
        $library->setStaticPropertyValue('depth', 1);

        try {
            $changed = $guard->restore();
        } finally {
            $left = $library->getStaticPropertyValue('depth');
            $library->setStaticPropertyValue('depth', 0);
        }

        $this->assertSame(['LibraryPcre window depth' => ['0', '1']], $changed);
        $this->assertSame(0, $left);
    }

    /**
     * Through PHPUnit itself: the leaking test fails the run and is named,
     * the test after it finds the limit it would have found alone, and a
     * run whose tests put everything back passes.
     */
    #[Test]
    public function test_a_test_leaving_a_limit_lowered_fails_the_run_and_the_next_test_finds_it_restored(): void
    {
        $root = sys_get_temp_dir().'/php-regex-pcre-guard-'.bin2hex(random_bytes(6));
        mkdir($root.'/tests', 0o777, true);

        file_put_contents($root.'/tests/LeakTest.php', <<<'PHP_WRAP'
            <?php

            final class LeakTest extends \PHPUnit\Framework\TestCase
            {
                public static string $found = '';

                public function test_leaves_the_limit_lowered(): void
                {
                    self::$found = (string) ini_get('pcre.backtrack_limit');
                    ini_set('pcre.backtrack_limit', '19');

                    $this->assertTrue(true);
                }

                public function test_finds_the_limit_restored(): void
                {
                    $this->assertSame(self::$found, ini_get('pcre.backtrack_limit'));
                }
            }
            PHP_WRAP);
        file_put_contents($root.'/tests/CleanTest.php', <<<'PHP_WRAP'
            <?php

            final class CleanTest extends \PHPUnit\Framework\TestCase
            {
                public function test_puts_the_limit_back(): void
                {
                    $saved = (string) ini_get('pcre.backtrack_limit');
                    try {
                        ini_set('pcre.backtrack_limit', '19');
                    } finally {
                        ini_set('pcre.backtrack_limit', $saved);
                    }

                    $this->assertTrue(true);
                }
            }
            PHP_WRAP);
        file_put_contents($root.'/bootstrap.php', "<?php\n\nrequire ".var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).";\n");
        file_put_contents($root.'/phpunit.xml', \sprintf(
            <<<'XML_WRAP'
                <?xml version="1.0" encoding="UTF-8"?>
                <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/12.5/phpunit.xsd" bootstrap="%s/bootstrap.php" executionOrder="default">
                    <extensions>
                        <bootstrap class="PHPRegex\Tests\Support\PcreSettingsGuard"/>
                    </extensions>
                    <testsuites>
                        <testsuite name="probe">
                            <directory>%s/tests</directory>
                        </testsuite>
                    </testsuites>
                </phpunit>
                XML_WRAP,
            $root,
            $root,
        ));

        try {
            [$leakExit, $leakOutput] = $this->runProbe($root.'/phpunit.xml', 'LeakTest');
            [$cleanExit, $cleanOutput] = $this->runProbe($root.'/phpunit.xml', 'CleanTest');

            $this->assertNotSame(0, $leakExit, "a test leaving a limit lowered must fail the run\n".$leakOutput);
            $this->assertStringContainsString('LeakTest::test_leaves_the_limit_lowered left the PCRE settings changed for the next test (pcre.backtrack_limit: found "', $leakOutput);
            $this->assertStringContainsString('left "19")', $leakOutput);
            $this->assertStringNotContainsString('test_finds_the_limit_restored left', $leakOutput);
            $this->assertStringContainsString('Tests: 2, Assertions: 2', $leakOutput, 'The test after the leak finds the limit restored.');
            $this->assertSame(0, $cleanExit, "a test putting the limit back passes\n".$cleanOutput);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * The probe runs under the PHPUnit the test itself runs under.
     *
     * @return array{0: int, 1: string}
     */
    private function runProbe(string $config, string $filter): array
    {
        // vendor/phpunit/phpunit/src/Framework/TestCase.php
        $phpunit = \dirname((string) (new \ReflectionClass(TestCase::class))->getFileName(), 5).'/bin/phpunit';

        $command = [\PHP_BINARY, $phpunit, '--configuration', $config, '--filter', $filter, '--no-progress'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
