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

use PHPRegex\Tests\Support\SourceDeprecationListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The deprecation policy of the suite: an E_DEPRECATED whose frame lands
 * under src/ fails the run — this library's own deprecations are never
 * silent — while everything a vendor or a tool triggers stays PHPUnit's
 * usual display-only signal, because the weekly re-resolved locks
 * deprecate for reasons that are not this library's.
 */
final class SourceDeprecationListenerTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFrames')]
    public function test_own_source_frames_are_the_only_failing_ones(string $file, bool $fails): void
    {
        $source = \dirname(__DIR__, 3).'/src';

        $this->assertSame($fails, SourceDeprecationListener::isOwnSource($file, $source), $file);
    }

    /**
     * @return iterable<string, array{file: string, fails: bool}>
     */
    public static function provideFrames(): iterable
    {
        $repo = \dirname(__DIR__, 3);

        yield 'a parser frame' => ['file' => $repo.'/src/Parser/ParserOptions.php', 'fails' => true];
        yield 'a bridge frame' => ['file' => $repo.'/src/Symfony/Security/SecurityConfigExtractor.php', 'fails' => true];
        yield 'a phpstan tool frame' => ['file' => $repo.'/tools/phpstan/vendor/phpstan/phpunit/src/Rules/Rule.php', 'fails' => false];
        yield 'a vendor frame' => ['file' => $repo.'/vendor/symfony/cache/Cache.php', 'fails' => false];
        yield 'a test frame' => ['file' => $repo.'/tests/Unit/Support/SourceDeprecationListenerTest.php', 'fails' => false];
    }

    #[Test]
    public function test_a_symlinked_or_relative_source_directory_still_matches(): void
    {
        $source = \dirname(__DIR__, 3).'/src';

        $this->assertTrue(SourceDeprecationListener::isOwnSource($source.'/Cli/Application.php', $source.'/./'));
    }

    #[Test]
    public function test_a_deprecation_under_the_source_fails_the_run_and_a_vendor_one_does_not(): void
    {
        $root = sys_get_temp_dir().'/php-regex-deprecations-'.bin2hex(random_bytes(6));
        $listener = \dirname(__DIR__, 3).'/tests/Support/SourceDeprecationListener.php';

        foreach (['src', 'vendor/probe', 'tests'] as $directory) {
            mkdir($root.'/'.$directory, 0o777, true);
        }
        file_put_contents($root.'/src/Legacy.php', <<<'PHP_WRAP'
            <?php

            namespace Probe;

            final class Legacy
            {
                public static function gone(): void
                {
                    trigger_error('the library deprecated itself', \E_USER_DEPRECATED);
                }

                public static function engineGone(): void
                {
                    // Null to a non-nullable parameter: deprecated from PHP
                    // 8.1 on, so the probe fires on every stack.
                    \str_contains(null, 'x');
                }
            }
            PHP_WRAP);
        file_put_contents($root.'/vendor/probe/Dep.php', <<<'PHP'
            <?php

            namespace Probe\Vendor;

            final class Dep
            {
                public static function gone(): void
                {
                    trigger_error('a vendor deprecation', \E_USER_DEPRECATED);
                }
            }
            PHP);
        file_put_contents($root.'/tests/OwnSourceTest.php', <<<'PHP_WRAP'
            <?php

            require_once __DIR__.'/../src/Legacy.php';

            use Probe\Legacy;

            final class OwnSourceTest extends \PHPUnit\Framework\TestCase
            {
                public function test_own_source_deprecation(): void
                {
                    Legacy::gone();

                    $this->assertTrue(true);
                }
            }
            PHP_WRAP);
        file_put_contents($root.'/tests/VendorDeprecationTest.php', <<<'PHP_WRAP'
            <?php

            require __DIR__.'/../vendor/probe/Dep.php';

            use Probe\Vendor\Dep;

            final class VendorDeprecationTest extends \PHPUnit\Framework\TestCase
            {
                public function test_vendor_deprecation(): void
                {
                    Dep::gone();

                    $this->assertTrue(true);
                }
            }
            PHP_WRAP);
        file_put_contents($root.'/tests/EngineDeprecationTest.php', <<<'PHP_WRAP'
            <?php

            require_once __DIR__.'/../src/Legacy.php';

            use Probe\Legacy;

            final class EngineDeprecationTest extends \PHPUnit\Framework\TestCase
            {
                public function test_engine_deprecation(): void
                {
                    Legacy::engineGone();

                    $this->assertTrue(true);
                }
            }
            PHP_WRAP);
        file_put_contents($root.'/bootstrap.php', sprintf(
            "<?php\n\nrequire %s;\nrequire %s;\nrequire %s;\n",
            var_export($listener, true),
            var_export(\dirname($listener).'/EngineDeprecationSubscriber.php', true),
            var_export(\dirname($listener).'/UserDeprecationSubscriber.php', true),
        ));
        file_put_contents($root.'/phpunit.xml', sprintf(
            <<<'XML_WRAP'
                <?xml version="1.0" encoding="UTF-8"?>
                <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/12.5/phpunit.xsd" bootstrap="%s/bootstrap.php" failOnWarning="true">
                    <extensions>
                        <bootstrap class="PHPRegex\Tests\Support\SourceDeprecationListener">
                            <parameter name="source" value="%s/src"/>
                        </bootstrap>
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
            $root,
        ));

        try {
            [$ownExit, $ownOutput] = $this->runProbe($root.'/phpunit.xml', 'OwnSourceTest');
            [$engineExit, $engineOutput] = $this->runProbe($root.'/phpunit.xml', 'EngineDeprecationTest');
            [$vendorExit, $vendorOutput] = $this->runProbe($root.'/phpunit.xml', 'VendorDeprecationTest');

            $this->assertNotSame(0, $ownExit, "a user-level deprecation under the guarded source must fail the run\n".$ownOutput);
            $this->assertNotSame(0, $engineExit, "an engine deprecation under the guarded source must fail the run\n".$engineOutput);
            $this->assertSame(0, $vendorExit, "a vendor deprecation stays informational\n".$vendorOutput);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    /**
     * The probe runs under the PHPUnit the test itself runs under: the
     * PHPUnit 10.5 of tools/phpunit-81 on PHP 8.1, the PHPUnit of
     * tools/phpunit, 11 or 12 as the PHP takes, everywhere else.
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
