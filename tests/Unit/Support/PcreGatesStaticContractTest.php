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

use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The gate contract, held where it is written instead of where it runs: a
 * release or a PHP version a gate may name is one of PcreGateLimits' lists,
 * and the repository's own PHPStan says so at the call site, before any
 * suite skips a row on every engine forever. PcreGateCallSitesTest reads
 * the literals a source file spells; this reads what the analysis makes of
 * a fixture calling each gate inside and outside the lists, with the
 * repository's phpstan.dist.neon — the strictest reading the suite gets.
 *
 * @phpstan-type ProbeError array{line: int, message: string, identifier: ?string}
 */
final class PcreGatesStaticContractTest extends TestCase
{
    use PcreGates;

    /**
     * @var list<ProbeError>|null
     */
    private static ?array $analysis = null;

    #[Test]
    public function test_a_gate_naming_a_judged_version_analyses_clean(): void
    {
        $this->requiresPhp('8.3', 'The repository\'s PHPStan run', 'cannot load PHPUnit 12, which its configuration bootstraps');

        foreach (['judgedRelease', 'judgedFloor', 'judgedCeiling', 'computedRelease'] as $marker) {
            $this->assertSame([], self::errorsOn($marker), $marker);
        }
    }

    #[Test]
    public function test_a_gate_naming_a_version_outside_the_lists_is_an_analysis_error(): void
    {
        $this->requiresPhp('8.3', 'The repository\'s PHPStan run', 'cannot load PHPUnit 12, which its configuration bootstraps');

        foreach (['releaseAboveTheCeiling' => '10.50', 'misspelledMinor' => '10.4', 'unjudgedFloor' => '8.7', 'unjudgedCeiling' => '5.0'] as $marker => $version) {
            $errors = self::errorsOn($marker);

            $this->assertCount(1, $errors, $marker);
            $this->assertSame('argument.type', $errors[0]['identifier'], $marker);
            $this->assertStringContainsString("'".$version."'", $errors[0]['message'], $marker);
        }
    }

    /**
     * Each call sits on its own line, marked by a trailing comment the
     * assertions find it by.
     */
    private static function fixtureCode(): string
    {
        return <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace RegexGateContractProbe;

            use PHPRegex\Tests\Support\PcreGates;
            use PHPUnit\Framework\TestCase;

            final class GateProbe extends TestCase
            {
                use PcreGates;

                public function judged(string $pattern): void
                {
                    $this->requiresPcre2('10.43', $pattern); // judgedRelease
                    $this->requiresPhp('8.4', $pattern, 'does not read it'); // judgedFloor
                    $this->forbidsPhp('8.5', $pattern, 'refuses it'); // judgedCeiling
                    $release = '/x/u' === $pattern ? '10.45' : '10.43';
                    $this->requiresPcre2($release, $pattern); // computedRelease
                }

                public function unjudged(string $pattern): void
                {
                    $this->requiresPcre2('10.50', $pattern); // releaseAboveTheCeiling
                    $this->requiresPcre2('10.4', $pattern); // misspelledMinor
                    $this->requiresPhp('8.7', $pattern, 'does not read it'); // unjudgedFloor
                    $this->forbidsPhp('5.0', $pattern, 'refuses it'); // unjudgedCeiling
                }
            }
            PHP_WRAP;
    }

    /**
     * @return list<ProbeError>
     */
    private static function errorsOn(string $marker): array
    {
        $line = null;
        foreach (explode("\n", self::fixtureCode()) as $index => $text) {
            if (str_ends_with($text, '// '.$marker)) {
                $line = $index + 1;
            }
        }
        self::assertNotNull($line, 'The fixture marks the line '.$marker);

        return array_values(array_filter(self::fixtureErrors(), static fn (array $error): bool => $error['line'] === $line));
    }

    /**
     * The repository's PHPStan on the fixture, analysed once per process.
     *
     * @return list<ProbeError>
     */
    private static function fixtureErrors(): array
    {
        if (null !== self::$analysis) {
            return self::$analysis;
        }

        $root = \dirname(__DIR__, 3);
        $directory = sys_get_temp_dir().'/regex-gate-contract-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $fixture = $directory.'/GateProbe.php';
        file_put_contents($fixture, self::fixtureCode());
        $stdoutFile = $directory.'/stdout';
        $stderrFile = $directory.'/stderr';

        try {
            // The exit code is not asserted: the fixture carries errors on
            // purpose, and the assertions read which ones.
            $process = proc_open(
                [\PHP_BINARY, $root.'/tools/phpstan/vendor/bin/phpstan', 'analyse', $fixture, '--error-format=json', '--no-progress'],
                [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
                $pipes,
                $root,
            );
            self::assertIsResource($process, 'The repository PHPStan binary must run: tools/phpstan is installed with the dev tools.');
            proc_close($process);

            $stdout = (string) file_get_contents($stdoutFile);
            $json = null;
            foreach (array_reverse(explode("\n", $stdout)) as $text) {
                if (str_starts_with($text, '{')) {
                    $json = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);

                    break;
                }
            }
            $files = \is_array($json) ? $json['files'] ?? null : null;
            if (!\is_array($files)) {
                self::fail('PHPStan must answer one JSON object; stdout: '.$stdout.' stderr: '.(string) file_get_contents($stderrFile));
            }

            $file = $files[$fixture] ?? [];
            self::assertIsArray($file);
            $messages = $file['messages'] ?? [];
            self::assertIsArray($messages);

            $errors = [];
            foreach ($messages as $error) {
                self::assertIsArray($error);
                $line = $error['line'] ?? null;
                $message = $error['message'] ?? null;
                self::assertIsInt($line);
                self::assertIsString($message);
                $identifier = $error['identifier'] ?? null;
                $errors[] = ['line' => $line, 'message' => $message, 'identifier' => \is_string($identifier) ? $identifier : null];
            }

            return self::$analysis = $errors;
        } finally {
            foreach ([$fixture, $stdoutFile, $stderrFile] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
