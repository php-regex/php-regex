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
 * Runs the PHPUnit bridge's four assertions once, end to end, against the
 * PHPUnit release a bare project installed: every assertion is asked to
 * pass where it must pass and to fail where it must fail, so a PHPUnit
 * major that changed how a constraint fails or renders is caught here,
 * on the release itself, not in a project that upgraded.
 *
 *     php tests/Tools/phpunit_bridge_selfcheck.php <project-dir>
 *
 * The project directory is the one the caller installed the package in
 * with Composer; its autoloader is the only one the script loads, so the
 * classes it exercises are the ones a consumer gets. Exits 1 on the first
 * check that answers anything but what it is asked to answer.
 */

namespace PHPRegex\Tests\Tools;

use PHPRegex\PHPUnit\RegexAssertions;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

$project = $argv[1] ?? '';
if ('' === $project || !is_file($project.'/vendor/autoload.php')) {
    fwrite(\STDERR, "usage: php tests/Tools/phpunit_bridge_selfcheck.php <project-dir>\n");
    fwrite(\STDERR, "       the directory holds a Composer install of php-regex/regex-phpunit and a phpunit/phpunit release.\n");
    exit(2);
}

require $project.'/vendor/autoload.php';

/**
 * The assertions where no TestCase exists: a bare subclass of Assert is
 * the narrowest surface they owe their behaviour to, and the one surface
 * every PHPUnit release shares. Anonymous, so the script leaves no
 * autoloadable symbol behind in the tree it lives in.
 */
$bridge = new class extends Assert {
    use RegexAssertions;
};

$checks = [
    'assertPatternMatches answers the vocabulary' => [false, static function () use ($bridge): void {
        $bridge::assertPatternMatches('/(?<alpha>a+)(?<beta>b+)?/', 'aa', ['alpha' => 'aa', 'beta' => false]);
    }],
    'assertPatternMatches answers a byte offset' => [false, static function () use ($bridge): void {
        $bridge::assertPatternMatches('/(\d{4})-(\d{2})/', '2026-10-17', [2 => ['10', 5]]);
    }],
    'assertPatternMatches fails on a wrong capture' => [true, static function () use ($bridge): void {
        $bridge::assertPatternMatches('/(\d{4})-(\d{2})/', '2026-10-17', [2 => '01']);
    }],
    'assertPatternMatches fails naming the real groups' => [true, static function () use ($bridge): void {
        $bridge::assertPatternMatches('/(?<alpha>a)(?<beta>b)/', 'ab', ['alpa' => 'a']);
    }],
    'assertPatternDoesNotMatch passes without a match' => [false, static function () use ($bridge): void {
        $bridge::assertPatternDoesNotMatch('/z/', 'abc');
    }],
    'assertPatternDoesNotMatch fails on a match' => [true, static function () use ($bridge): void {
        $bridge::assertPatternDoesNotMatch('/b/', 'abc');
    }],
    'assertPatternCompiles passes a pattern the engine takes' => [false, static function () use ($bridge): void {
        $bridge::assertPatternCompiles('/^\d{4}-\d{2}-\d{2}$/');
    }],
    'assertPatternCompiles fails on the engine\'s refusal' => [true, static function () use ($bridge): void {
        $bridge::assertPatternCompiles('/(?<name>a)(?<name>b)/');
    }],
    'assertPatternIsLinear passes a proven-linear pattern' => [false, static function () use ($bridge): void {
        $bridge::assertPatternIsLinear('/^[a-z0-9-]{3,}$/');
    }],
    'assertPatternIsLinear fails proven unsafe' => [true, static function () use ($bridge): void {
        $bridge::assertPatternIsLinear('/^(a+)+$/');
    }],
];

foreach ($checks as $name => [$mustFail, $run]) {
    try {
        $run();
        if ($mustFail) {
            fwrite(\STDERR, "[FAIL] {$name}: the assertion passed where it must fail.\n");
            exit(1);
        }
        echo "[ok] {$name}\n";
    } catch (ExpectationFailedException $failure) {
        if (!$mustFail) {
            fwrite(\STDERR, "[FAIL] {$name}: the assertion failed where it must pass.\n".$failure->getMessage()."\n");
            exit(1);
        }
        echo '[ok] '.$name.' ('.explode("\n", $failure->getMessage())[0].")\n";
    }
}

echo "the four assertions answer and fail as asked under the installed release.\n";
