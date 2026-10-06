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

namespace PHPRegex\Tests\Integration\Bridge\Rector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every rewrite a fixture expects is replayed on the engine: the code before
 * and the code after run on the same subjects (empty, the literals, around
 * them, inside them, repeated, with a newline, with bytes above 0x7F) and
 * must answer alike, value for value, error for error. A fixture that a rule
 * passes is then a rewrite the engine agrees with.
 */
final class EngineReplayTest extends TestCase
{
    private const SPLIT = "\n-----\n";

    private const SUBJECTS = ['', "\n", "\r\n", "\xff", "\x80", "\0", 'x', ',', ',,', 'foo', 'FOO', 'GET', 'POST', 'aaa', "foo\n", 'it\'s', 'a$b'];

    #[Test]
    #[DataProvider('provideRewrites')]
    public function test_rewrite_answers_as_the_call_it_replaces(string $fixture): void
    {
        $content = (string) file_get_contents($fixture);
        [$before, $after] = explode(self::SPLIT, $content, 2);
        $this->assertNotSame($before, $after, $fixture.' rewrites nothing.');

        $original = self::load($before, $fixture, 'Before');
        $rewritten = self::load($after, $fixture, 'After');

        foreach (self::subjects($content) as $subject) {
            $this->assertSame(
                self::outcome($original, $subject),
                self::outcome($rewritten, $subject),
                \sprintf('%s: the rewrite answers otherwise on "%s" (hex).', basename($fixture), bin2hex($subject)),
            );
        }
    }

    /**
     * A call a fixture leaves alone because the engine can fail on it where
     * the string function answers: replayed on the subject that makes it
     * fail, the call returns the failure value.
     */
    #[Test]
    #[DataProvider('provideFailingCalls')]
    public function test_call_left_alone_fails_where_a_string_function_answers(string $fixture, string $subject, mixed $failure): void
    {
        $content = (string) file_get_contents(__DIR__.'/'.$fixture);
        $this->assertStringNotContainsString(self::SPLIT, $content, $fixture.' is left alone.');

        $this->assertSame(['returns' => $failure], self::outcome(self::load($content, $fixture, 'Before'), $subject));
    }

    /**
     * @return iterable<string, array{fixture: string, subject: string, failure: mixed}>
     */
    public static function provideFailingCalls(): iterable
    {
        // Forty "a" and a "c": every path of the ambiguous alternation is
        // tried at every position, past the backtrack limit.
        $subject = str_repeat('a', 40).'c'.str_repeat('a', 20).'b';

        yield 'match, ambiguous alternation' => ['fixture' => 'PregMatchToStringComparisonRector/Fixture/skip_ambiguous_alternation.php.inc', 'subject' => $subject, 'failure' => false];
        yield 'replace, ambiguous alternation' => ['fixture' => 'PregReplaceToStrReplaceRector/Fixture/skip_ambiguous_alternation.php.inc', 'subject' => $subject, 'failure' => null];
        yield 'split, ambiguous alternation' => ['fixture' => 'PregSplitToExplodeRector/Fixture/skip_ambiguous_alternation.php.inc', 'subject' => $subject, 'failure' => false];
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public static function provideRewrites(): iterable
    {
        $fixtures = glob(__DIR__.'/*/Fixture*/*.php.inc') ?: [];
        sort($fixtures);

        foreach ($fixtures as $fixture) {
            if (str_contains((string) file_get_contents($fixture), self::SPLIT)) {
                yield substr($fixture, \strlen(__DIR__) + 1) => ['fixture' => $fixture];
            }
        }
    }

    /**
     * Declares the fixture's class under a namespace of its own and returns
     * the class name.
     */
    private static function load(string $code, string $fixture, string $side): string
    {
        self::assertSame(1, preg_match('~^namespace ([\w\\\\]+);$~m', $code, $namespace), $fixture.' declares a namespace.');
        self::assertSame(1, preg_match('~^final class (\w+)$~m', $code, $class), $fixture.' declares a final class.');

        $replay = __NAMESPACE__.'\\Replay\\N'.md5($fixture).'\\'.$side;
        $fqcn = $replay.'\\'.$class[1];
        if (class_exists($fqcn, false)) {
            return $fqcn;
        }

        $code = str_replace('namespace '.$namespace[1].';', 'namespace '.$replay.';', $code);
        $code = (string) preg_replace('~\?>\s*$~', '', $code);
        $file = tempnam(sys_get_temp_dir(), 'php-regex-replay-');
        self::assertIsString($file);

        try {
            file_put_contents($file, $code);
            require $file;
        } finally {
            unlink($file);
        }

        self::assertTrue(class_exists($fqcn, false), $fixture.' declares '.$class[1].'.');

        return $fqcn;
    }

    /**
     * What run() returns for the subject, or the error it raises.
     *
     * @return array{returns: mixed}|array{raises: string}
     */
    private static function outcome(string $class, string $subject): array
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $instance = new $class();
            self::assertTrue(method_exists($instance, 'run'));

            return ['returns' => $instance->run($subject)];
        } catch (\ErrorException|\Error $error) {
            return ['raises' => $error::class.': '.$error->getMessage()];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The shared subjects, and around every string literal of the fixture:
     * the literal, repeated, surrounded, cut, with a newline, between high
     * bytes and upper-cased.
     *
     * @return list<string>
     */
    private static function subjects(string $content): array
    {
        $subjects = self::SUBJECTS;
        foreach (token_get_all($content) as $token) {
            if (!\is_array($token) || \T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                continue;
            }

            $literal = self::value($token[1]);
            if ('' === $literal) {
                continue;
            }

            array_push(
                $subjects,
                $literal,
                $literal.$literal,
                $literal.$literal.$literal,
                'x'.$literal,
                $literal.'x',
                'x'.$literal.'y'.$literal.'z',
                $literal."\n",
                "\n".$literal,
                "\xff".$literal."\x80",
                substr($literal, 1),
                substr($literal, 0, -1),
                strtoupper($literal),
            );
        }

        return array_values(array_unique($subjects));
    }

    /**
     * The value of a quoted PHP string token without interpolation.
     */
    private static function value(string $token): string
    {
        $body = substr($token, 1, -1);

        return "'" === $token[0] ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($body);
    }
}
