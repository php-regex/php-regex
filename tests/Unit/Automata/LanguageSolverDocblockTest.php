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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\LanguageSolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The class docblock says what the solver reads: a plain lookaround is read,
 * which LookaroundSolverTest holds, so the docblock does not list
 * lookarounds among what throws.
 */
final class LanguageSolverDocblockTest extends TestCase
{
    #[Test]
    public function test_the_docblock_says_plain_lookarounds_are_read(): void
    {
        $doc = (string) (new \ReflectionClass(LanguageSolver::class))->getDocComment();

        $this->assertStringNotContainsString('backreferences, lookarounds, recursion', $doc);
        $this->assertStringContainsString('plain lookaround', $doc);
        $this->assertTrue((new LanguageSolver())->subsetOf('/a(?=b)b/', '/ab/')->isSubset);
    }

    #[Test]
    public function test_no_source_comment_names_the_product_regex_parser(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()
                && 1 === preg_match('~\b(?:using|with|by|of|in) RegexParser\b(?![:\\\\(])~', (string) file_get_contents($file->getPathname()))) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'The product is PHPRegex; RegexParser is a class.');
    }
}
