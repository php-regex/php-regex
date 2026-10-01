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
use PHPRegex\Parser\Internal\PatternParser;
use PHPUnit\Framework\TestCase;

final class ParserInternalErrorTest extends TestCase
{
    /**
     * Teste le cas improbable où le délimiteur n'est pas trouvé dans extractPatternAndFlags
     * (normalement attrapé avant par le check de longueur).
     */
    public function test_extract_pattern_no_delimiter(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\\/) or use a different delimiter, e.g. #abcdef#.');

        // On passe une chaîne longue mais sans délimiteur de fin valide
        PatternParser::extractPatternAndFlags('/abcdef');
    }
}
