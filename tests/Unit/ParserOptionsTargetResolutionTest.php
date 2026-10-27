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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP, the PCRE2 and the locale a pattern is judged for are resolved
 * in one place: a PCRE2 release named is used as is, older or newer than
 * the one the PHP bundles; the running PHP gets the PCRE2 it links; a PHP
 * named gets the one its sources bundle. The options, the lint and the
 * bridges read their target from there.
 */
final class ParserOptionsTargetResolutionTest extends TestCase
{
    /**
     * @param int|null    $php      the PHP_VERSION_ID named, null for the running PHP
     * @param string|null $pcre     the PCRE2 release named
     * @param int|null    $expected the PHP_VERSION_ID judged, null for the running one
     */
    #[Test]
    #[DataProvider('provideResolutions')]
    public function test_the_target_is_resolved_from_what_is_named(?int $php, ?string $pcre, ?int $expected, string $expectedPcre): void
    {
        $target = ParserOptions::resolveTarget($php, $pcre);

        $this->assertSame($expected ?? \PHP_VERSION_ID, $target->phpVersionId);
        $this->assertSame($expectedPcre, $target->pcreVersion);
    }

    /**
     * @return iterable<string, array{php: int|null, pcre: string|null, expected: int|null, expectedPcre: string}>
     */
    public static function provideResolutions(): iterable
    {
        $running = PcreTarget::runtime()->pcreVersion;

        yield 'nothing named: the running engine' => ['php' => null, 'pcre' => null, 'expected' => null, 'expectedPcre' => $running];
        yield 'a PHP named: the PCRE2 it bundles' => ['php' => 80200, 'pcre' => null, 'expected' => 80200, 'expectedPcre' => '10.40'];
        yield 'the running PHP named: the PCRE2 it bundles' => ['php' => \PHP_VERSION_ID, 'pcre' => null, 'expected' => \PHP_VERSION_ID, 'expectedPcre' => PcreTarget::bundledWith(\PHP_VERSION_ID)->pcreVersion];
        yield 'a release older than the bundled one, as is' => ['php' => 80400, 'pcre' => '10.39', 'expected' => 80400, 'expectedPcre' => '10.39'];
        yield 'a release newer than the bundled one, as is' => ['php' => 80200, 'pcre' => '10.49', 'expected' => 80200, 'expectedPcre' => '10.49'];
        yield 'a release beside the running PHP, as is' => ['php' => null, 'pcre' => '10.42', 'expected' => null, 'expectedPcre' => '10.42'];
        yield 'a release as PCRE_VERSION spells it' => ['php' => 80300, 'pcre' => '10.44 2024-06-07', 'expected' => 80300, 'expectedPcre' => '10.44'];
    }

    #[Test]
    public function test_the_locale_named_is_kept_and_the_runtime_s_by_default(): void
    {
        $this->assertSame('C', ParserOptions::resolveTarget(80400, null, 'C')->locale);
        $this->assertSame(PcreTarget::runtime()->locale, ParserOptions::resolveTarget(80400)->locale);
    }

    #[Test]
    public function test_a_release_that_names_none_is_refused(): void
    {
        try {
            ParserOptions::resolveTarget(80400, 'ten');
            $this->fail('A release that names none was resolved.');
        } catch (InvalidRegexOptionException $e) {
            $this->assertSame('"pcre_version" must be a PCRE2 release like "10.44", not "ten".', $e->getMessage());
        }
    }

    /**
     * The options and the lint resolve alike: the same names give the
     * same target, the floor and every later point of a range.
     */
    #[Test]
    public function test_the_options_and_the_lint_resolve_alike(): void
    {
        foreach ([[null, null], ['runtime', null], ['8.2', null], ['8.4', '10.39'], [(string) \PHP_VERSION_ID, null], [null, '10.42']] as [$php, $pcre]) {
            $options = array_filter(['php_version' => $php, 'pcre_version' => $pcre], static fn (?string $value): bool => null !== $value);
            $lint = ProjectTarget::fromSources(['--php-version' => $php], ['--pcre-version' => $pcre], null, []);
            $named = null === $php || 'runtime' === $php ? null : ParserOptions::phpVersionIdOf($php);

            $this->assertSame(ParserOptions::resolveTarget($named, $pcre)->key(), $lint->target()->key());
            $this->assertSame(ParserOptions::fromArray($options)->target->key(), $lint->target()->key());
        }

        $directory = sys_get_temp_dir().'/phpregex-target-resolution-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents($directory.'/composer.json', '{"require": {"php": "^8.2"}}');
            $range = ProjectTarget::fromSources([], [], $directory, [])->range();
            $this->assertGreaterThan(1, \count($range));
            foreach ($range as $point) {
                $this->assertSame(ParserOptions::resolveTarget($point->phpVersionId)->key(), $point->key());
            }

            $pinned = ProjectTarget::fromSources([], ['--pcre-version' => '10.39'], $directory, [])->range();
            foreach ($pinned as $point) {
                $this->assertSame(ParserOptions::resolveTarget($point->phpVersionId, '10.39')->key(), $point->key());
            }
        } finally {
            @unlink($directory.'/composer.json');
            @rmdir($directory);
        }
    }
}
