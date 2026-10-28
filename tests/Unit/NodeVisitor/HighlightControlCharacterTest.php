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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A literal the pattern spells with an escape ("\e", "\x07") is the byte it
 * stands for in the tree: the highlighters show it escaped, never the raw
 * control byte a terminal or a page would act on or drop.
 */
final class HighlightControlCharacterTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_a_control_character_is_shown_escaped(string $format): void
    {
        $highlighted = Regex::create(['cache' => null])->highlight('/a\\e]0;Xb\\x07c/', $format);

        $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', (string) preg_replace('/\x1B\[[0-9;]*m/', '', $highlighted)), bin2hex($highlighted));
        $this->assertStringContainsString('\x1B', $highlighted);
        $this->assertStringContainsString('\x07', $highlighted);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideFormats(): iterable
    {
        yield 'console' => ['format' => 'console'];
        yield 'html' => ['format' => 'html'];
    }
}
