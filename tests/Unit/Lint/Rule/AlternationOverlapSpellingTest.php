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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The message quotes the overlapping branches with their hidden characters
 * spelled as the pattern could hold them: "\x{202E}" in UTF mode, its bytes
 * "\xE2\x80\xAE" otherwise, where PCRE refuses "\x{202E}" (PHP 8.4.26 /
 * PCRE2 10.49: "character code point value in \x{} or \o{} is too large").
 */
final class AlternationOverlapSpellingTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_message_spells_a_hidden_character_as_the_pattern_could_hold_it(string $pattern, string $spelled): void
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if ('regex.lint.alternation.overlap' === $issue->id) {
                $messages[] = $issue->message;
            }
        }

        $this->assertCount(1, $messages, $pattern);
        $this->assertStringContainsString('"x'.$spelled.'"', $messages[0]);
    }

    /**
     * @return iterable<string, array{pattern: string, spelled: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'without u' => ['pattern' => "/(?:x\u{202E}|x\u{202E}y)+/", 'spelled' => '\xE2\x80\xAE'];
        yield 'under u' => ['pattern' => "/(?:x\u{202E}|x\u{202E}y)+/u", 'spelled' => '\x{202E}'];
    }
}
