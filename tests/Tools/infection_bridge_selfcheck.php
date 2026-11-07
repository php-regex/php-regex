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

/*
 * Runs Infection on a small project with the six regex mutators, as a user
 * would, and checks what it reports: every mutant comes from the six, some
 * are killed and some escape, the escaped ones say what kills them, and a
 * second run reports the same mutants. The project directory holds a
 * Composer install of php-regex/regex-infection and PHPUnit; Infection is
 * the binary given, installed through Composer or downloaded as its PHAR.
 *
 *   php tests/Tools/infection_bridge_selfcheck.php <project-dir> <infection-binary>
 */

namespace PHPRegex\Tests\Tools;

$project = $argv[1] ?? '';
$infection = $argv[2] ?? '';
if ('' === $project || !is_file($project.'/vendor/autoload.php') || '' === $infection || !is_file($infection)) {
    fwrite(\STDERR, "usage: php tests/Tools/infection_bridge_selfcheck.php <project-dir> <infection-binary>\n");
    fwrite(\STDERR, "       the directory holds a Composer install of php-regex/regex-infection and phpunit/phpunit;\n");
    fwrite(\STDERR, "       the binary is Infection, from vendor/bin or its PHAR.\n");
    exit(2);
}

$fail = static function (string $message): never {
    fwrite(\STDERR, $message."\n");
    exit(1);
};

const MUTATORS = ['RegexQuantifier', 'RegexAnchor', 'RegexCharClass', 'RegexAlternation', 'RegexFlag', 'RegexEscape'];

// What the fixture's suite kills and misses, release after release: a patch
// release keeps the mutants, so a change here is a change of behaviour.
const KILLED = 8;
const ESCAPED = 24;

$fixture = __DIR__.'/../Fixtures/Infection/project';
$files = ['src/Slug.php', 'tests/SlugCheck.php', 'phpunit.xml.dist', 'infection.json5'];
// The fixture goes into a project that holds none of it: a project's own
// configuration is never written over.
$taken = array_values(array_filter($files, static fn (string $file): bool => file_exists($project.'/'.$file)));
if ([] !== $taken) {
    fwrite(\STDERR, 'The project already holds '.implode(', ', $taken).": give a project of its own to the check.\n");
    exit(2);
}
foreach ($files as $file) {
    @mkdir(\dirname($project.'/'.$file), 0o777, true);
    copy($fixture.'/'.$file, $project.'/'.$file) || $fail('Could not copy '.$file.' into '.$project.'.');
}

/**
 * The mutants of one run: status, mutator and diff, sorted.
 *
 * @return list<array{string, string, string}>
 */
$run = static function () use ($project, $infection, $fail): array {
    @unlink($project.'/infection-log.json');
    $command = [\PHP_BINARY, $infection, '--threads=1', '--no-progress', '--no-interaction', '--configuration=infection.json5'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project);
    if (!\is_resource($process)) {
        $fail('Could not start Infection.');
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if (0 !== $status) {
        $fail("Infection exited with {$status}:\n".$output);
    }

    $log = json_decode((string) @file_get_contents($project.'/infection-log.json'), true);
    if (!\is_array($log)) {
        $fail("Infection wrote no JSON log:\n".$output);
    }

    $mutants = [];
    foreach (['killed', 'escaped', 'errored', 'syntaxErrors', 'timeouted', 'uncovered', 'ignored'] as $outcome) {
        foreach (\is_array($log[$outcome] ?? null) ? $log[$outcome] : [] as $entry) {
            $name = \is_array($entry) && \is_array($entry['mutator'] ?? null) ? ($entry['mutator']['mutatorName'] ?? null) : null;
            $diff = \is_array($entry) ? ($entry['diff'] ?? null) : null;
            if (!\is_string($name) || !\is_string($diff)) {
                $fail('A '.$outcome.' entry of the log has no mutator name or diff.');
            }
            $mutants[] = [$outcome, $name, $diff];
        }
    }
    sort($mutants);

    return $mutants;
};

$first = $run();
$byOutcome = [];
foreach ($first as [$outcome, $name, $diff]) {
    \in_array($name, MUTATORS, true) || $fail('Infection ran a mutator that is not one of the six: '.$name.'.');
    \in_array($outcome, ['killed', 'escaped'], true) || $fail("A mutant was {$outcome}, not killed or escaped:\n".$diff);
    $byOutcome[$outcome][] = $diff;
}

KILLED === \count($byOutcome['killed'] ?? []) || $fail(\sprintf('%d mutants were killed, the fixture expects %d.', \count($byOutcome['killed'] ?? []), KILLED));
ESCAPED === \count($byOutcome['escaped'] ?? []) || $fail(\sprintf('%d mutants escaped, the fixture expects %d.', \count($byOutcome['escaped'] ?? []), ESCAPED));
foreach ($byOutcome['escaped'] as $diff) {
    str_contains($diff, '(?#kill: "') || $fail("An escaped mutant carries no kill string:\n".$diff);
}

$first === $run() || $fail('A second run reported other mutants.');

printf("Infection ran %d mutants of the six regex mutators: %d killed, %d escaped, each with its kill string, twice alike.\n", \count($first), \count($byOutcome['killed']), \count($byOutcome['escaped']));
