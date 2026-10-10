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

namespace PHPRegex\Tests\Unit\Lsp\Document;

use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The occurrence pipeline is a list end to end: RegexFinder::find() builds
 * its occurrences ordered by offset and 0-based (array_values() after the
 * ksort, or the appended sequence), DocumentManager stores find()'s result
 * per URI, and every consumer — code actions, completion, the position
 * lookup — iterates it. The docblocks still say array<RegexOccurrence>,
 * which promises nothing about keys; list<RegexOccurrence> says what the
 * producers build and the iteration order the language server relies on.
 */
final class OccurrenceListContractTest extends TestCase
{
    #[Test]
    public function test_document_manager_stores_lists_per_uri(): void
    {
        $property = new \ReflectionProperty(DocumentManager::class, 'occurrences');
        $docComment = (string) $property->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@var\s+([^\n]+)/', $docComment, $var),
            'DocumentManager::$occurrences must document what it stores: find()\'s result, one list per URI.',
        );

        $this->assertMatchesRegularExpression(
            '/^array\s*<\s*string\s*,\s*list\s*<\s*RegexOccurrence\s*>\s*>/',
            trim($var[1]),
            sprintf(
                'The $occurrences map must say array<string, list<RegexOccurrence>> — each URI\'s entry is the'
                .' 0-based ordered list RegexFinder::find() built, not an array with unknown keys; the "URI =>'
                .' occurrences" prose stays on the line. Today: %s.',
                trim($var[1]),
            ),
        );
    }

    #[Test]
    #[DataProvider('provideOccurrenceLists')]
    public function test_occurrence_producer_declares_the_list_it_builds(string $class, string $method): void
    {
        $docComment = (string) (new \ReflectionMethod($class, $method))->getDocComment();

        $this->assertMatchesRegularExpression(
            '/@return\s+list\s*<\s*RegexOccurrence\s*>/',
            $docComment,
            sprintf(
                '%s::%s() must declare @return list<RegexOccurrence>: it hands its callers the occurrences as a'
                .' 0-based ordered list — DocumentManager stores find()\'s result whole, getOccurrences() hands it'
                .' back, and findKnownCalls() is the list find() merges — so the promise belongs in the type, not'
                .' in the callers\' assumptions.',
                $class,
                $method,
            ),
        );
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function provideOccurrenceLists(): iterable
    {
        yield 'the manager reads them back' => [DocumentManager::class, 'getOccurrences'];

        yield 'the finder entry point' => [RegexFinder::class, 'find'];

        yield 'the known-calls half of the merge' => [RegexFinder::class, 'findKnownCalls'];
    }
}
