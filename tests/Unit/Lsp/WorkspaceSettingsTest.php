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

namespace PHPRegex\Tests\Unit\Lsp;

use PHPRegex\LanguageServer\Protocol\TraceLevel;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\LanguageServer\WorkspaceSettings;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Path;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What "initialize" says of the workspace: its root, regex.json there, the
 * initializationOptions, and what the server reads from them, each value
 * that cannot be read a warning on the editor's log, the next source read
 * in its place.
 */
final class WorkspaceSettingsTest extends TestCase
{
    /**
     * @var resource
     */
    private $output;

    private Transport $transport;

    private string $root = '';

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'w+');
        if (false === $output) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->transport = new Transport($output);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        if ('' !== $this->root) {
            foreach (glob($this->root.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->root);
        }
    }

    #[Test]
    public function test_workspace_root_is_the_first_folder_else_the_root_uri(): void
    {
        $root = $this->workspace();
        $elsewhere = \dirname($root);

        $this->assertSame($root, $this->read(['workspaceFolders' => [['uri' => FileUri::of($root)], ['uri' => FileUri::of($elsewhere)]], 'rootUri' => FileUri::of($elsewhere)])->root);
        $this->assertSame($root, $this->read(['workspaceFolders' => null, 'rootUri' => FileUri::of($root)])->root);
        $this->assertSame($root, $this->read(['workspaceFolders' => [], 'rootUri' => FileUri::of($root)])->root);
        $this->assertSame($root, $this->read(['workspaceFolders' => [FileUri::of($elsewhere)], 'rootUri' => FileUri::of($root)])->root);
    }

    #[Test]
    public function test_workspace_root_reads_a_percent_encoded_path(): void
    {
        $root = $this->workspace('a b');

        $this->assertSame($root, $this->read(['rootUri' => FileUri::of($root)])->root);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('provideRootlessParams')]
    public function test_workspace_without_a_local_directory_has_no_root_and_no_config(array $params): void
    {
        $settings = $this->read($params);

        $this->assertNull($settings->root);
        $this->assertSame([], $settings->config);
        $this->assertSame([], $this->frames());
    }

    /**
     * @return iterable<string, array{params: array<string, mixed>}>
     */
    public static function provideRootlessParams(): iterable
    {
        yield 'no root' => ['params' => []];
        yield 'not a file URI' => ['params' => ['rootUri' => 'untitled:Untitled-1']];
        yield 'not a string' => ['params' => ['rootUri' => 42]];
        yield 'a directory that does not exist' => ['params' => ['rootUri' => 'file:///no/such/directory/at/all']];
        yield 'a file' => ['params' => ['rootUri' => FileUri::of(__FILE__)]];
        yield 'a folder without uri' => ['params' => ['workspaceFolders' => [['name' => 'a']]]];
    }

    #[Test]
    public function test_workspace_reads_regex_json_and_logs_what_it_ignores(): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"paths": "src", "exclude": ["var", "vendor"], "extraction": {"functions": ["x#a"]}}');

        $settings = $this->read(['rootUri' => FileUri::of($root)]);

        $this->assertSame($root, $settings->root);
        $this->assertSame(['src'], $settings->paths());
        $this->assertSame(['var', 'vendor'], $settings->exclude());
        $this->assertSame([['jsonrpc' => '2.0', 'method' => 'window/logMessage', 'params' => ['type' => 2, 'message' => 'regex.json: Malformed pattern function "x#a", ignored.']]], $this->frames());
    }

    #[Test]
    public function test_workspace_reads_without_regex_json_it_cannot_read_and_shows_why(): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"paths": 3}');

        $settings = $this->read(['rootUri' => FileUri::of($root)]);

        $this->assertSame([], $settings->config);
        $frames = $this->frames();
        $this->assertCount(2, $frames);
        $this->assertIsArray($frames[0]);
        $this->assertIsArray($frames[1]);
        $this->assertSame(['window/logMessage', 'window/showMessage'], [$frames[0]['method'] ?? null, $frames[1]['method'] ?? null]);
        $this->assertSame($frames[0]['params'] ?? null, $frames[1]['params'] ?? null);
        $this->assertIsArray($frames[0]['params'] ?? null);
        $this->assertSame(1, $frames[0]['params']['type'] ?? null);
        $this->assertIsString($frames[0]['params']['message'] ?? null);
        $this->assertStringStartsWith('regex.json ignored: ', $frames[0]['params']['message']);
    }

    #[Test]
    public function test_workspace_reads_every_path_and_leaves_vendor_out_by_default(): void
    {
        $settings = $this->read([]);

        $this->assertSame([], $settings->paths());
        $this->assertSame(['vendor'], $settings->exclude());
    }

    #[Test]
    public function test_workspace_exclude_regex_json_names_replaces_vendor(): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"paths": ["src", "lib"], "exclude": []}');

        $settings = $this->read(['rootUri' => FileUri::of($root)]);

        $this->assertSame(['src', 'lib'], $settings->paths());
        $this->assertSame([], $settings->exclude());
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideRedosSettings')]
    public function test_workspace_redos_reads_the_option_then_regex_json(array $options, string $config, RedosMode $mode, RedosSeverity $threshold): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', $config);

        $settings = $this->read(['rootUri' => FileUri::of($root), 'initializationOptions' => $options]);

        $this->assertSame([$mode, $threshold], $settings->redos($settings->lintArguments()));
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, config: string, mode: RedosMode, threshold: RedosSeverity}>
     */
    public static function provideRedosSettings(): iterable
    {
        yield 'off by default' => ['options' => [], 'config' => '{}', 'mode' => RedosMode::Off, 'threshold' => RedosSeverity::High];
        yield 'on through the option' => ['options' => ['redos' => true], 'config' => '{}', 'mode' => RedosMode::Theoretical, 'threshold' => RedosSeverity::High];
        yield 'on through regex.json' => ['options' => [], 'config' => '{"checks": {"redos": {"enabled": true, "mode": "confirmed", "threshold": "low"}}}', 'mode' => RedosMode::Confirmed, 'threshold' => RedosSeverity::Low];
        yield 'off through the option over regex.json' => ['options' => ['redos' => false], 'config' => '{"checks": {"redos": {"enabled": true}}}', 'mode' => RedosMode::Off, 'threshold' => RedosSeverity::High];
        yield 'an option that is no boolean' => ['options' => ['redos' => 'yes'], 'config' => '{"checks": {"redos": {"enabled": true}}}', 'mode' => RedosMode::Theoretical, 'threshold' => RedosSeverity::High];
        yield 'a threshold of safe' => ['options' => ['redos' => true], 'config' => '{"checks": {"redos": {"threshold": "safe"}}}', 'mode' => RedosMode::Theoretical, 'threshold' => RedosSeverity::High];
        yield 'a threshold of unknown' => ['options' => ['redos' => true], 'config' => '{"checks": {"redos": {"threshold": "unknown"}}}', 'mode' => RedosMode::Theoretical, 'threshold' => RedosSeverity::High];
        yield 'a threshold of critical' => ['options' => ['redos' => true], 'config' => '{"checks": {"redos": {"threshold": "critical"}}}', 'mode' => RedosMode::Theoretical, 'threshold' => RedosSeverity::Critical];
    }

    #[Test]
    public function test_workspace_target_reads_the_options_over_regex_json(): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"phpVersion": "8.1", "pcreVersion": "10.39", "locale": "C"}');

        $target = $this->read(['rootUri' => FileUri::of($root), 'initializationOptions' => ['phpVersion' => '8.3', 'pcreVersion' => '10.42']])->target();

        $this->assertSame(80300, $target->target()->phpVersionId);
        $this->assertSame('10.42', $target->target()->pcreVersion);
        $this->assertSame('C', $target->target()->locale);
        $this->assertSame([], $this->frames());
    }

    #[Test]
    public function test_workspace_target_reads_regex_json_where_the_options_say_nothing(): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"phpVersion": 80100, "pcreVersion": "10.42", "locale": "C"}');

        $target = $this->read(['rootUri' => FileUri::of($root)])->target();

        $this->assertSame(80100, $target->target()->phpVersionId);
        $this->assertSame('10.42', $target->target()->pcreVersion);
    }

    #[Test]
    public function test_workspace_target_passes_over_options_it_cannot_read_with_a_warning(): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"phpVersion": "8.2", "pcreVersion": "10.40"}');

        $target = $this->read(['rootUri' => FileUri::of($root), 'initializationOptions' => ['phpVersion' => 'eight', 'pcreVersion' => ['10.44'], 'locale' => 'xx_NOWHERE.UTF-8']])->target();

        $this->assertSame(80200, $target->target()->phpVersionId);
        $this->assertSame('10.40', $target->target()->pcreVersion);
        $this->assertSame([
            'initializationOptions.pcreVersion array is not a version: ignored.',
            'initializationOptions.locale "xx_NOWHERE.UTF-8" is not a locale this machine can set: ignored.',
            'initializationOptions.phpVersion "eight" is not a version: ignored.',
        ], $this->logged(2));
    }

    /**
     * The PCRE2 of the target follows the rule of every entry point: a pin
     * as is, composer.json's config.platform.lib-pcre at the workspace root
     * included; else the running PCRE2 for the running PHP's minor; else the
     * one that PHP bundles.
     */
    #[Test]
    public function test_workspace_target_reads_the_pcre2_by_the_rule_of_every_entry_point(): void
    {
        $runningMinor = intdiv(\PHP_VERSION_ID, 100) * 100;
        $this->assertSame(PcreTarget::runtime()->pcreVersion, $this->read(['initializationOptions' => ['phpVersion' => $runningMinor]])->target()->target()->pcreVersion);

        $root = $this->workspace();
        file_put_contents($root.'/composer.json', '{"config": {"platform": {"php": "8.2", "lib-pcre": "10.43"}}}');
        $this->assertSame('10.43', $this->read(['rootUri' => FileUri::of($root)])->target()->target()->pcreVersion);
        $this->assertSame('10.39', $this->read(['rootUri' => FileUri::of($root), 'initializationOptions' => ['pcreVersion' => '10.39']])->target()->target()->pcreVersion);
    }

    #[Test]
    public function test_workspace_target_takes_an_integer_php_version(): void
    {
        $target = $this->read(['initializationOptions' => ['phpVersion' => 80400, 'locale' => 'C']])->target();

        $this->assertSame(80400, $target->target()->phpVersionId);
        $this->assertSame('C', $target->target()->locale);
        $this->assertSame([], $this->frames());
    }

    /**
     * initializationOptions.phpVersion takes what regex.json takes: a
     * single version, a Composer constraint, or {"min", "max"}; a range is
     * judged at each point it allows.
     *
     * @param array<string, mixed> $options
     * @param list<int>            $expected PHP_VERSION_IDs, floor first
     */
    #[Test]
    #[DataProvider('provideRangeOptions')]
    public function test_workspace_target_reads_a_range_from_the_options(array $options, array $expected): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"phpVersion": "8.0"}');

        $target = $this->read(['rootUri' => FileUri::of($root), 'initializationOptions' => $options])->target();

        $this->assertSame($expected, array_map(static fn (PcreTarget $point): int => $point->phpVersionId, $target->range()));
        $this->assertSame('initializationOptions', $target->source());
        $this->assertSame([], $this->frames());
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, expected: list<int>}>
     */
    public static function provideRangeOptions(): iterable
    {
        yield 'a constraint' => ['options' => ['phpVersion' => '>=8.3'], 'expected' => [80300, 80400, 80425, 80500, 80510]];
        yield 'a hyphen range' => ['options' => ['phpVersion' => '8.1 - 8.3'], 'expected' => [80100, 80200, 80300]];
        yield 'an object of strings' => ['options' => ['phpVersion' => ['min' => '8.2', 'max' => '8.4']], 'expected' => [80200, 80300, 80400, 80425]];
        yield 'an object of PHP_VERSION_IDs' => ['options' => ['phpVersion' => ['min' => 80200, 'max' => 80400]], 'expected' => [80200, 80300, 80400]];
        yield 'a single version stays exact' => ['options' => ['phpVersion' => '8.4'], 'expected' => [80400]];
    }

    /**
     * @param list<int> $expected PHP_VERSION_IDs, floor first
     */
    #[Test]
    #[DataProvider('provideRangeConfigs')]
    public function test_workspace_target_reads_a_range_from_regex_json(string $config, array $expected): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', $config);
        file_put_contents($root.'/composer.json', '{"require": {"php": "8.0.*"}}');

        $target = $this->read(['rootUri' => FileUri::of($root)])->target();

        $this->assertSame($expected, array_map(static fn (PcreTarget $point): int => $point->phpVersionId, $target->range()));
        $this->assertSame('regex.json', $target->source());
        $this->assertSame([], $this->frames());
    }

    /**
     * @return iterable<string, array{config: string, expected: list<int>}>
     */
    public static function provideRangeConfigs(): iterable
    {
        yield 'a constraint' => ['config' => '{"phpVersion": "^8.4"}', 'expected' => [80400, 80425, 80500, 80510]];
        yield 'an object of strings' => ['config' => '{"phpVersion": {"min": "8.1", "max": "8.3"}}', 'expected' => [80100, 80200, 80300]];
        yield 'an object of PHP_VERSION_IDs' => ['config' => '{"phpVersion": {"min": 80300, "max": 80699}}', 'expected' => [80300, 80400, 80425, 80500, 80510]];
    }

    /**
     * A range the options cannot name is passed over with the warning any
     * value that is not a version gets, and the next source is read.
     *
     * @param list<string> $warnings
     */
    #[Test]
    #[DataProvider('provideUnreadableRangeOptions')]
    public function test_workspace_target_passes_over_a_range_it_cannot_read_with_a_warning(mixed $phpVersion, array $warnings): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"phpVersion": "8.2"}');

        $target = $this->read(['rootUri' => FileUri::of($root), 'initializationOptions' => ['phpVersion' => $phpVersion]])->target();

        $this->assertSame([80200], array_map(static fn (PcreTarget $point): int => $point->phpVersionId, $target->range()));
        $this->assertSame('regex.json', $target->source());
        $this->assertSame($warnings, $this->logged(2));
    }

    /**
     * @return iterable<string, array{phpVersion: mixed, warnings: list<string>}>
     */
    public static function provideUnreadableRangeOptions(): iterable
    {
        yield 'no lowest version' => ['phpVersion' => '<8.4', 'warnings' => ['initializationOptions.phpVersion "<8.4" is not a version: ignored.']];
        yield 'a PHP_VERSION_ID inside a constraint' => ['phpVersion' => '>=80100', 'warnings' => ['initializationOptions.phpVersion ">=80100" is not a version: ignored.']];
        yield 'runtime inside a constraint' => ['phpVersion' => 'runtime || ^9.0', 'warnings' => ['initializationOptions.phpVersion "runtime || ^9.0" is not a version: ignored.']];
        yield 'an object without max' => ['phpVersion' => ['min' => '8.1'], 'warnings' => ['initializationOptions.phpVersion array is not a version: ignored.']];
        yield 'an object with another key' => ['phpVersion' => ['min' => '8.1', 'max' => '8.6', 'x' => 1], 'warnings' => ['initializationOptions.phpVersion array is not a version: ignored.']];
        yield 'min above max' => ['phpVersion' => ['min' => '8.6', 'max' => '8.1'], 'warnings' => ['initializationOptions.phpVersion array is not a version: ignored.']];
        yield 'runtime as a bound' => ['phpVersion' => ['min' => 'runtime', 'max' => '8.6'], 'warnings' => ['initializationOptions.phpVersion array is not a version: ignored.']];
        // A constraint no PHP satisfies names no range, as min above max
        // does.
        yield 'a hyphen range written backwards' => ['phpVersion' => '8.6 - 8.1', 'warnings' => ['initializationOptions.phpVersion "8.6 - 8.1" is not a version: ignored.']];
        yield 'an upper bound below the lowest version' => ['phpVersion' => '>=8.4 <8.2', 'warnings' => ['initializationOptions.phpVersion ">=8.4 <8.2" is not a version: ignored.']];
        yield 'two exact versions ANDed' => ['phpVersion' => '8.1 8.2', 'warnings' => ['initializationOptions.phpVersion "8.1 8.2" is not a version: ignored.']];
        // A version past PHP 9.99 is refused inside a constraint as alone;
        // one past every integer is no TypeError.
        yield 'a constraint from PHP 10' => ['phpVersion' => '>=10.0', 'warnings' => ['initializationOptions.phpVersion ">=10.0" is not a version: ignored.']];
        yield 'a constraint from PHP 99.1' => ['phpVersion' => '>=99.1', 'warnings' => ['initializationOptions.phpVersion ">=99.1" is not a version: ignored.']];
        yield 'a constraint from a major past every integer' => ['phpVersion' => '>=99999999999999999999.1', 'warnings' => ['initializationOptions.phpVersion ">=99999999999999999999.1" is not a version: ignored.']];
        // What regex.json and --php-version refuse too.
        yield 'a doubled separator' => ['phpVersion' => '>=8.1,,<8.3', 'warnings' => ['initializationOptions.phpVersion ">=8.1,,<8.3" is not a version: ignored.']];
        yield 'a bound with a leading zero' => ['phpVersion' => ['min' => '08.1', 'max' => '8.3'], 'warnings' => ['initializationOptions.phpVersion array is not a version: ignored.']];
    }

    #[Test]
    public function test_workspace_target_refuses_a_php_on_pcre1(): void
    {
        $this->expectException(UnsupportedTargetException::class);

        $this->read(['initializationOptions' => ['phpVersion' => '7.2']])->target();
    }

    #[Test]
    public function test_workspace_target_refuses_what_regex_json_cannot_name(): void
    {
        $root = $this->workspace();
        file_put_contents($root.'/regex.json', '{"locale": "xx_NOWHERE.UTF-8"}');

        $this->expectException(InvalidRegexOptionException::class);

        $this->read(['rootUri' => FileUri::of($root)])->target();
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $warnings
     */
    #[Test]
    #[DataProvider('provideTraceLevels')]
    public function test_workspace_trace_level_reads_the_option_then_the_protocol(array $params, TraceLevel $level, array $warnings): void
    {
        $this->assertSame($level, WorkspaceSettings::traceLevel($params, $this->transport));
        $this->assertSame($warnings, $this->logged(2));
    }

    /**
     * @return iterable<string, array{params: array<string, mixed>, level: TraceLevel, warnings: list<string>}>
     */
    public static function provideTraceLevels(): iterable
    {
        yield 'off by default' => ['params' => [], 'level' => TraceLevel::Off, 'warnings' => []];
        yield 'the protocol' => ['params' => ['trace' => 'messages'], 'level' => TraceLevel::Messages, 'warnings' => []];
        yield 'the option over the protocol' => ['params' => ['trace' => 'messages', 'initializationOptions' => ['trace' => 'verbose']], 'level' => TraceLevel::Verbose, 'warnings' => []];
        yield 'an option that is no level' => ['params' => ['trace' => 'messages', 'initializationOptions' => ['trace' => 'loud']], 'level' => TraceLevel::Messages, 'warnings' => ['initializationOptions.trace "loud" is not off, messages or verbose: ignored.']];
        yield 'neither is a level' => ['params' => ['trace' => 3, 'initializationOptions' => ['trace' => false]], 'level' => TraceLevel::Off, 'warnings' => ['initializationOptions.trace bool is not off, messages or verbose: ignored.', 'trace int is not off, messages or verbose: ignored.']];
        yield 'options that are no object' => ['params' => ['trace' => 'verbose', 'initializationOptions' => 'verbose'], 'level' => TraceLevel::Verbose, 'warnings' => []];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function read(array $params): WorkspaceSettings
    {
        return WorkspaceSettings::read($params, $this->transport);
    }

    private function workspace(string $name = 'workspace'): string
    {
        $root = sys_get_temp_dir().'/php-regex-workspace-'.bin2hex(random_bytes(4)).'-'.$name;
        mkdir($root);
        $this->root = $root;

        return Path::fromHost((string) realpath($root));
    }

    /**
     * @return list<mixed>
     */
    private function frames(): array
    {
        rewind($this->output);
        $frames = preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($this->output), -1, \PREG_SPLIT_NO_EMPTY);
        $this->assertIsArray($frames);

        return array_map(static fn (string $frame): mixed => json_decode($frame, true, 512, \JSON_THROW_ON_ERROR), $frames);
    }

    /**
     * @return list<mixed> the messages logged with the type
     */
    private function logged(int $type): array
    {
        $messages = [];
        foreach ($this->frames() as $frame) {
            if (\is_array($frame) && 'window/logMessage' === ($frame['method'] ?? null) && \is_array($frame['params'] ?? null) && $type === ($frame['params']['type'] ?? null)) {
                $messages[] = $frame['params']['message'] ?? null;
            }
        }

        return $messages;
    }
}
