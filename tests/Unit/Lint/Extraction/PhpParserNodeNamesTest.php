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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\StaticVar;
use PhpParser\Node\UseItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The linter takes PHP-Parser from 4.18. PHP-Parser 5 renamed ten nodes
 * and kept the former names as aliases: a node named as PHP-Parser 5
 * names it is no class of PHP-Parser 4, where "instanceof" is then false
 * and the node is passed over without a word, an integer, an interpolated
 * string or an array item among them. The linter names these nodes as
 * both majors know them.
 *
 * PHP-Parser 4.18.0 ships Node\Scalar\LNumber, DNumber, Encapsed and
 * EncapsedStringPart, Node\Expr\ArrayItem and ClosureUse, and
 * Node\Stmt\DeclareDeclare, PropertyProperty, StaticVar and UseUse, and
 * none of the names below; PHP-Parser 5 declares each of the former as an
 * alias of the latter.
 */
final class PhpParserNodeNamesTest extends TestCase
{
    /**
     * The names PHP-Parser 5 gave, which PHP-Parser 4 does not know.
     */
    private const RENAMED = [
        Int_::class,
        Float_::class,
        InterpolatedString::class,
        InterpolatedStringPart::class,
        ArrayItem::class,
        ClosureUse::class,
        DeclareItem::class,
        PropertyItem::class,
        StaticVar::class,
        UseItem::class,
    ];

    #[Test]
    public function test_the_linter_names_no_node_php_parser_4_does_not_know(): void
    {
        $root = \dirname(__DIR__, 4).'/src/Linter';
        $named = [];
        $read = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'PhpParser\\')) {
                continue;
            }

            $read++;
            foreach (self::RENAMED as $name) {
                if (1 === preg_match('/\b'.preg_quote($name, '/').'\b(?!\\\\)/', $source)) {
                    $named[] = substr($file->getPathname(), \strlen($root) + 1).': '.$name;
                }
            }
        }

        $this->assertGreaterThan(0, $read);
        sort($named, \SORT_STRING);
        $this->assertSame([], $named);
    }
}
