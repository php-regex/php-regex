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

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Language;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Minimization\MinimizationAlgorithm;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Automata\Solver\EquivalenceResult;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Automata\Solver\IntersectionResult;
use PHPRegex\Automata\Solver\MatchEquivalenceResult;
use PHPRegex\Automata\Solver\SubsetResult;
use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Automata\TrivialMatchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The automata package promises a short list of names; every other class in
 * it says it is internal, so nothing becomes public by being forgotten.
 */
final class PublicSurfaceTest extends TestCase
{
    private const PUBLIC = [
        LanguageSolver::class,
        SolverOptions::class,
        MatchMode::class,
        DeterminizationAlgorithm::class,
        MinimizationAlgorithm::class,
        EquivalenceResult::class,
        MatchEquivalenceResult::class,
        TrivialMatchClassifier::class,
        TrivialMatch::class,
        TrivialMatchKind::class,
        Language::class,
        IntersectionResult::class,
        SubsetResult::class,
        Dfa::class,
        DfaState::class,
        DfaCacheInterface::class,
        InMemoryDfaCache::class,
        ComplexityException::class,
    ];

    #[Test]
    #[DataProvider('provideClasses')]
    public function test_a_class_is_public_or_says_it_is_internal(string $class): void
    {
        $this->assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), $class.' is not declared by its file');

        $internal = str_contains((string) (new \ReflectionClass($class))->getDocComment(), '@internal');

        if (\in_array($class, self::PUBLIC, true)) {
            $this->assertFalse($internal, $class.' is public and must not say @internal');

            return;
        }

        $this->assertTrue($internal, $class.' is not in the public list and must say @internal');
    }

    #[Test]
    public function test_every_public_name_is_a_class_of_the_package(): void
    {
        $classes = array_merge(...array_values(iterator_to_array(self::provideClasses())));

        foreach (self::PUBLIC as $class) {
            $this->assertContains($class, $classes);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideClasses(): iterable
    {
        $root = \dirname(__DIR__, 3).'/src/Automata';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $classes = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1, -4);
            $classes[] = 'PHPRegex\\Automata\\'.str_replace('/', '\\', $relative);
        }

        sort($classes);

        foreach ($classes as $class) {
            yield $class => [$class];
        }
    }
}
