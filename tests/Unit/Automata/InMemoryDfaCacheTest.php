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
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory DFA cache is shared by every analysis of a process: it
 * keeps a bounded number of automata and empties with the library's other
 * caches.
 */
final class InMemoryDfaCacheTest extends TestCase
{
    #[Test]
    public function test_a_full_cache_drops_its_older_half(): void
    {
        $dfa = (new LanguageSolver())->compile('/a/');
        $cache = new InMemoryDfaCache();

        for ($i = 0; $i < StaticCaches::MAX_ENTRIES; $i++) {
            $cache->set('key'.$i, $dfa);
        }
        $this->assertSame($dfa, $cache->get('key0'), 'A cache at its bound still holds every entry.');

        $cache->set('one more', $dfa);

        $this->assertNull($cache->get('key0'));
        $this->assertNull($cache->get('key'.(int) (StaticCaches::MAX_ENTRIES / 2 - 1)));
        $this->assertSame($dfa, $cache->get('key'.(int) (StaticCaches::MAX_ENTRIES / 2)));
        $this->assertSame($dfa, $cache->get('key'.(StaticCaches::MAX_ENTRIES - 1)));
        $this->assertSame($dfa, $cache->get('one more'));
    }

    #[Test]
    public function test_clearing_the_caches_empties_every_live_cache(): void
    {
        $dfa = (new LanguageSolver())->compile('/a/');
        $first = new InMemoryDfaCache();
        $second = new InMemoryDfaCache();
        $first->set('a', $dfa);
        $second->set('b', $dfa);

        RegexParser::create()->clearCaches();

        $this->assertNull($first->get('a'));
        $this->assertNull($second->get('b'));

        $first->set('a', $dfa);
        $this->assertSame($dfa, $first->get('a'), 'A cleared cache fills again.');
    }
}
