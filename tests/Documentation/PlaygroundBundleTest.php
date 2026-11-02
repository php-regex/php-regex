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

use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The bundle is what the browser engine loads: the core library and the
 * driver, stripped of comments, as one JSON file. It is generated, so the
 * tests hold its shape and its self-sufficiency, not its freshness against
 * src/: the home runs the snapshot its manifest names, by design.
 */
#[Group('documentation')]
final class PlaygroundBundleTest extends TestCase
{
    #[Test]
    public function test_the_manifest_is_complete(): void
    {
        $manifest = PlaygroundDriver::bundle()['manifest'];

        $this->assertIsString($manifest['version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[A-Z0-9]+)?$/', $manifest['version']);
        $this->assertIsString($manifest['commit']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $manifest['commit']);
        $this->assertIsString($manifest['built']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $manifest['built']);
        $this->assertGreaterThanOrEqual(500, $manifest['files']);
        $this->assertIsInt($manifest['bytes']);
        $this->assertSame(1, $manifest['protocol']);
        $this->assertSame(JsonDumper::VERSION, $manifest['jsonDumper']);
        $this->assertSame(\count(PlaygroundDriver::bundle()['files']), $manifest['files']);
    }

    #[Test]
    public function test_the_bundle_holds_the_core_the_driver_and_nothing_else(): void
    {
        $files = PlaygroundDriver::bundle()['files'];

        $this->assertArrayHasKey('driver.php', $files);
        foreach (array_keys($files) as $path) {
            if ('driver.php' === $path) {
                continue;
            }
            $this->assertStringStartsWith('src/', $path);
            $this->assertStringEndsWith('.php', $path);
            foreach (['src/Cli/', 'src/LanguageServer/', 'src/Laravel/', 'src/Symfony/', 'src/PHPStan/', 'src/Psalm/', 'src/Rector/', 'src/PHPUnit/'] as $outside) {
                $this->assertStringStartsNotWith($outside, $path);
            }
            foreach (['/Tests/', '/Resources/', '/bin/'] as $outside) {
                $this->assertStringNotContainsString($outside, $path);
            }
            $this->assertFileExists(PlaygroundDriver::ROOT.'/'.$path, $path.' is in the bundle but no longer in the tree: regenerate the bundle.');
        }
    }

    #[Test]
    public function test_the_bundled_driver_is_the_tree_driver(): void
    {
        $this->assertSame(
            php_strip_whitespace(PlaygroundDriver::TREE_DRIVER),
            PlaygroundDriver::bundle()['files']['driver.php'],
            'The driver changed: regenerate the bundle so the page and the engine run the same one.',
        );
    }

    #[Test]
    public function test_the_bundle_is_self_sufficient(): void
    {
        $bundle = PlaygroundDriver::extractBundle();
        $default = self::defaultInput();

        $run = PlaygroundDriver::run($bundle['driver'], $bundle['root'], ['op' => 'analyze', 'regex' => $default['regex'], 'subject' => $default['subject']]);
        $this->assertSame('', $run['stderr'], 'A stripped file missing a dependency would complain here.');
        $payload = PlaygroundDriver::payload($run);
        $this->assertArrayNotHasKey('fatal', $payload);
        $this->assertSame([], $payload['errors']);

        $lint = PlaygroundDriver::payload(PlaygroundDriver::run($bundle['driver'], $bundle['root'], ['op' => 'lint', 'regex' => $default['regex']]));
        $this->assertIsArray($lint['lint']);
    }

    #[Test]
    public function test_the_bundle_is_stripped_and_bounded(): void
    {
        $bundle = PlaygroundDriver::bundle();

        foreach ($bundle['files'] as $path => $source) {
            $this->assertStringNotContainsString('/**', $source, $path.' still carries a docblock.');
            $this->assertStringStartsWith('<?php', $source, $path);
        }
        $this->assertLessThan(3 * 1024 * 1024, filesize(PlaygroundDriver::BUNDLE));
        $this->assertSame(array_sum(array_map(strlen(...), $bundle['files'])), $bundle['manifest']['bytes']);
    }

    /**
     * @return array{regex: string, subject: string}
     */
    private static function defaultInput(): array
    {
        /** @var array{default: array{regex: string, subject: string}} $data */
        $data = json_decode((string) file_get_contents(PlaygroundDriver::DEFAULT_DATA), true, 512, \JSON_THROW_ON_ERROR);

        return ['regex' => $data['default']['regex'], 'subject' => $data['default']['subject']];
    }
}
