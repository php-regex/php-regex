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

namespace PHPRegex\Tests\Support;

/**
 * Runs the playground's PHP driver the way the browser engine does, on the
 * local PHP: one process per request, the input in a JSON file, the answer
 * as one JSON document between two record separators on stdout.
 *
 * The driver exists twice: the source in the tree, which the tests run over
 * src/, and its stripped copy inside the bundle, which they run over the
 * bundle's own files, the combination a visitor actually runs. Both go
 * through here.
 *
 * @phpstan-type Engine array{php: string, pcre: string, jit: bool, backtrackLimit: int}
 * @phpstan-type Target array{php: string, pcre: string}
 * @phpstan-type Input array{regexBytes: int, subjectBytes: int, truncated: bool}
 * @phpstan-type Delimited array{delimiter: string, flags: string, bodyStart: int, bodyEnd: int}
 * @phpstan-type Node array{type: string, start: int, end: int}
 * @phpstan-type Capture array{string, int, int}
 * @phpstan-type Matches array{count: int, error: string, shown: int, cap: int, names: array<string, string>, sets: list<array<int, Capture>>}
 * @phpstan-type Fault array{source: string, class: ?string, message: string, position: ?int, code: ?string, hint: ?string}
 * @phpstan-type Finding array{id: string, message: string, offset: ?int, end: ?int, severity: string, hint: ?string}
 * @phpstan-type Analysis array{protocol: int, engine: Engine, library: array{version: string, jsonDumper: int}, target: Target, input: Input, delimited: Delimited|null, unitOf: list<int>, ast: array<string, mixed>|null, groupNames: array<string, string>, explain: ?string, errors: list<Fault>, engineError: ?string, matches: Matches|null, timing: array{analyzeMs: float, matchMs: float|null}}
 * @phpstan-type Match array{protocol: int, engine: Engine, input: Input, engineError: ?string, matches: Matches|null, timing: array{matchMs: float}}
 * @phpstan-type Lint array{protocol: int, lint: list<Finding>|null, timing: array{lintMs: float}}
 * @phpstan-type Description array{protocol: int, index: int, start: ?int, end: ?int, text: ?string}
 */
final class PlaygroundDriver
{
    public const ROOT = __DIR__.'/../..';
    public const TREE_DRIVER = self::ROOT.'/docs/assets/playground/driver.php';
    public const BUNDLE = self::ROOT.'/docs/assets/playground/bundle.json';
    public const DEFAULT_DATA = self::ROOT.'/docs/_data/playground.json';
    public const SRC = self::ROOT.'/src';

    /**
     * @var array{driver: string, root: string}|null
     */
    private static ?array $extracted = null;

    /**
     * The two drivers and the roots they run over: the tree's over src/,
     * the bundle's over the bundle's files.
     *
     * @return iterable<string, array{driver: string, root: string}>
     */
    public static function drivers(): iterable
    {
        yield 'tree driver over src/' => ['driver' => self::TREE_DRIVER, 'root' => self::SRC];

        $bundle = self::extractBundle();
        yield 'bundled driver over the bundle' => ['driver' => $bundle['driver'], 'root' => $bundle['root']];
    }

    /**
     * @param array<string, mixed> $input the request, as the worker writes it
     * @param list<string>         $ini   extra ini settings, as "name=value"
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function run(string $driver, string $root, array $input, array $ini = [], int $requireTimes = 1): array
    {
        $dir = sys_get_temp_dir().'/phpregex-playground-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $inputFile = $dir.'/in.json';
        file_put_contents($inputFile, json_encode($input, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));
        $runner = $dir.'/run.php';
        $requires = str_repeat(sprintf('require %s;', var_export($driver, true)), $requireTimes);
        file_put_contents($runner, sprintf(
            '<?php define("PHPREGEX_PLAYGROUND_ROOT", %s); %s echo phpregex_playground_run(%s);',
            var_export($root, true),
            $requires,
            var_export($inputFile, true),
        ));

        // The local php.ini stays: a PHP that loads mbstring from conf.d
        // would have none under -n. Only a prepend file is set aside.
        $command = [\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL'];
        foreach ($ini as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = $runner;

        $stdoutFile = $dir.'/out';
        $stderrFile = $dir.'/err';
        $process = proc_open($command, [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes);
        \assert(\is_resource($process));

        $deadline = microtime(true) + 20.0;
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(5000);
            }
        } while ($status['running'] && microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process);
            proc_close($process);
            self::remove($dir);

            return ['exit' => 124, 'stdout' => '', 'stderr' => 'stopped after 20 s'];
        }

        $exit = $status['exitcode'];
        proc_close($process);
        $result = [
            'exit' => $exit,
            'stdout' => (string) file_get_contents($stdoutFile),
            'stderr' => (string) file_get_contents($stderrFile),
        ];
        self::remove($dir);

        return $result;
    }

    /**
     * The JSON document inside the envelope, in the driver's shape.
     *
     * @param array{exit: int, stdout: string, stderr: string} $run
     *
     * @return array<string, mixed>
     */
    public static function payload(array $run): array
    {
        if (1 !== preg_match('/\A\x1e(.*)\x1e\z/s', $run['stdout'], $m)) {
            throw new \RuntimeException(\sprintf("No envelope on stdout (exit %d).\nstdout: %s\nstderr: %s", $run['exit'], $run['stdout'], $run['stderr']));
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($m[1], true, 8192, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * Runs a request and returns its payload, untyped: for the envelope
     * itself and the answers that carry no shape (a fatal, an unknown op).
     *
     * @param array<string, mixed> $input
     * @param list<string>         $ini
     *
     * @return array<string, mixed>
     */
    public static function ask(string $driver, string $root, array $input, array $ini = []): array
    {
        return self::payload(self::run($driver, $root, $input, $ini));
    }

    /**
     * @param Target|null  $target
     * @param list<string> $ini
     *
     * @return Analysis
     */
    public static function analyze(string $driver, string $root, string $regex, string $subject, ?array $target = null, array $ini = [], bool $match = true): array
    {
        $input = ['op' => 'analyze', 'regex' => $regex, 'subject' => $subject, 'match' => $match];
        if (null !== $target) {
            $input['target'] = $target;
        }
        /** @var Analysis $answer */
        $answer = self::ask($driver, $root, $input, $ini);

        return $answer;
    }

    /**
     * @param list<string> $ini
     *
     * @return Match
     */
    public static function match(string $driver, string $root, string $regex, string $subject, array $ini = []): array
    {
        /** @var Match $answer */
        $answer = self::ask($driver, $root, ['op' => 'match', 'regex' => $regex, 'subject' => $subject], $ini);

        return $answer;
    }

    /**
     * @param Target|null $target
     *
     * @return Lint
     */
    public static function lint(string $driver, string $root, string $regex, ?array $target = null): array
    {
        $input = ['op' => 'lint', 'regex' => $regex];
        if (null !== $target) {
            $input['target'] = $target;
        }
        /** @var Lint $answer */
        $answer = self::ask($driver, $root, $input);

        return $answer;
    }

    /**
     * @return Description
     */
    public static function describe(string $driver, string $root, string $regex, int $index): array
    {
        /** @var Description $answer */
        $answer = self::ask($driver, $root, ['op' => 'describe', 'regex' => $regex, 'index' => $index]);

        return $answer;
    }

    /**
     * A node of a dumped document, by key: the essentials every node carries.
     *
     * @param array<string, mixed> $document
     *
     * @return Node
     */
    public static function node(array $document, string $key): array
    {
        $node = $document[$key] ?? null;
        if (!\is_array($node) || !\is_string($node['type'] ?? null) || !\is_int($node['start'] ?? null) || !\is_int($node['end'] ?? null)) {
            throw new \RuntimeException(\sprintf('No node under "%s".', $key));
        }

        return ['type' => $node['type'], 'start' => $node['start'], 'end' => $node['end']];
    }

    /**
     * The bundle's files written to a temporary directory, once per process:
     * the driver at <dir>/driver.php, the library under <dir>/src.
     *
     * @return array{driver: string, root: string}
     */
    public static function extractBundle(): array
    {
        if (null !== self::$extracted) {
            return self::$extracted;
        }

        $bundle = self::bundle();
        $dir = sys_get_temp_dir().'/phpregex-playground-bundle-'.bin2hex(random_bytes(6));
        mkdir($dir);
        foreach ($bundle['files'] as $path => $source) {
            $target = $dir.'/'.$path;
            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0o777, true);
            }
            file_put_contents($target, $source);
        }

        return self::$extracted = ['driver' => $dir.'/driver.php', 'root' => $dir.'/src'];
    }

    /**
     * @return array{manifest: array<string, mixed>, files: array<string, string>}
     */
    public static function bundle(): array
    {
        /** @var array{manifest: array<string, mixed>, files: array<string, string>} $bundle */
        $bundle = json_decode((string) file_get_contents(self::BUNDLE), true, 512, \JSON_THROW_ON_ERROR);

        return $bundle;
    }

    private static function remove(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
