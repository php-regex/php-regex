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
     * @return iterable<string, array{string, bool}>
     */
    public static function provideFrames(): iterable
    {
        $repo = \dirname(__DIR__, 3);

        yield 'a parser frame' => [$repo.'/src/Parser/ParserOptions.php', true];
        yield 'a bridge frame' => [$repo.'/src/Symfony/Security/SecurityConfigExtractor.php', true];
        yield 'a phpstan tool frame' => [$repo.'/tools/phpstan/vendor/phpstan/phpstan/src/Rules/Rule.php', false];
        yield 'a vendor frame' => [$repo.'/vendor/symfony/cache/Cache.php', false];
        yield 'a test frame' => [$repo.'/tests/Unit/Support/SourceDeprecationListenerTest.php', false];
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
        file_put_contents($root.'/src/Legacy.php', <<<'PHP'
            <?php

            namespace Probe;

            final class Legacy
            {
                public static function gone(): void
                {
                    trigger_error('the library deprecated itself', \E_USER_DEPRECATED);
                }
            }
            PHP);
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
        file_put_contents($root.'/tests/OwnSourceTest.php', <<<'PHP'
            <?php

            require __DIR__.'/../src/Legacy.php';

            use Probe\Legacy;

            final class OwnSourceTest extends \PHPUnit\Framework\TestCase
            {
                public function test_own_source_deprecation(): void
                {
                    Legacy::gone();

                    $this->assertTrue(true);
                }
            }
            PHP);
        file_put_contents($root.'/tests/VendorDeprecationTest.php', <<<'PHP'
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
            PHP);
        file_put_contents($root.'/bootstrap.php', sprintf("<?php\n\nrequire %s;\n", var_export($listener, true)));
        file_put_contents($root.'/phpunit.xml', sprintf(
            <<<'XML'
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
                XML,
            $root,
            $root,
            $root,
        ));

        try {
            [$ownExit, $ownOutput] = $this->runProbe($root.'/phpunit.xml', 'OwnSourceTest');
            [$vendorExit] = $this->runProbe($root.'/phpunit.xml', 'VendorDeprecationTest');

            $this->assertNotSame(0, $ownExit, "a deprecation under the guarded source must fail the run\n".$ownOutput);
            $this->assertSame(0, $vendorExit, 'a vendor deprecation stays informational');
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runProbe(string $config, string $filter): array
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3).'/tools/phpunit/vendor/bin/phpunit', '--configuration', $config, '--filter', $filter, '--no-progress'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
