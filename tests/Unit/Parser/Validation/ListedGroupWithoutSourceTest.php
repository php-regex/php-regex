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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A tree without its source text still refuses a group a substring scan
 * names and the pattern lacks: the text only places the caret. PHP
 * refuses the pattern (8.4.27, PCRE2 10.49).
 */
final class ListedGroupWithoutSourceTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideMissingListedGroups')]
    public function test_a_missing_listed_group_is_refused_without_the_source(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, 'a'));

        // Read as a release that knows the substring scan, 10.45 and later.
        $tree = RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse($pattern);
        $withoutSource = new RegexNode($tree->pattern, $tree->flags, $tree->delimiter, $tree->startPosition, $tree->endPosition);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessageIsOrContains('Group 2 is listed but does not exist.');

        $withoutSource->accept(new Validator());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMissingListedGroups(): iterable
    {
        yield 'a substring scan' => ['pattern' => '/(*scs:(2)a)/'];
    }
}
