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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserStructureTest extends TestCase
{
    use PhpUnitForwardCompat;

    /**
     * Teste la limite de longueur du pattern.
     */
    #[Test]
    public function test_parse_exceeds_max_length(): void
    {
        // On configure une limite très basse pour le test
        $regex = Regex::create(['max_pattern_length' => 10]);

        $this->expectException(ResourceLimitException::class);
        $this->expectExceptionMessageIsOrContains('Regex pattern exceeds the maximum length');

        $regex->parse('/this_is_too_long/');
    }

    /**
     * Teste une regex trop courte (sans délimiteurs).
     */
    #[Test]
    public function test_parse_too_short(): void
    {
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Regex is too short');

        $regex->parse('/'); // Juste un caractère
    }

    /**
     * Teste l'absence de délimiteur de fermeture valide.
     */
    #[Test]
    public function test_parse_no_closing_delimiter(): void
    {
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\\/) or use a different delimiter, e.g. #abc#.');

        $regex->parse('/abc'); // Pas de slash final
    }

    /**
     * Teste le cas subtil où le délimiteur final est échappé.
     */
    #[Test]
    public function test_parse_escaped_closing_delimiter_at_end(): void
    {
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\\/) or use a different delimiter, e.g. #abc\\/#.');

        // Ici le dernier slash est échappé, donc ce n'est pas un délimiteur valide
        $regex->parse('/abc\/');
    }

    /**
     * Teste l'utilisation de flags inconnus.
     */
    #[Test]
    public function test_parse_unknown_flags(): void
    {
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Unknown regex flag(s) found: "k".');

        $regex->parse('/abc/k'); // 'k' n'est pas un flag valide
    }
}
