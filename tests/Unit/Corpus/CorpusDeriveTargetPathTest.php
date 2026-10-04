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

namespace PHPRegex\Tests\Unit\Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * deriveTargetPath(), required straight from bin/corpus under the CORPUS_INCLUDED
 * guard: every URL form the corpus manifest has ever seen, plus the spellings
 * git accepts but a human would not write on purpose. The target must ALWAYS
 * be "vendor/name" — a bare name never says which vendor it belongs to.
 */
final class CorpusDeriveTargetPathTest extends TestCase
{
    /**
     * @param array{url: string, target: ?string, exception: ?string} $row
     */
    #[Test]
    #[DataProvider('provideUrls')]
    public function test_derive_target_path_always_yields_vendor_name(array $row): void
    {
        if (null !== $row['exception']) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($row['exception']);
        }

        $target = deriveTargetPath($row['url']);

        $this->assertSame($row['target'], $target);
    }

    /**
     * @return iterable<string, array{array{url: string, target: ?string, exception: ?string}}>
     */
    public static function provideUrls(): iterable
    {
        $yield = static fn (string $url, ?string $target, ?string $exception = null): array => [
            ['url' => $url, 'target' => $target, 'exception' => $exception],
        ];

        yield 'https with .git' => $yield('https://github.com/composer/semver.git', 'composer/semver');
        yield 'https without .git' => $yield('https://github.com/composer/semver', 'composer/semver');
        yield 'https with trailing slash' => $yield('https://github.com/composer/semver.git/', 'composer/semver');
        yield 'https with several trailing slashes' => $yield('https://github.com/composer/semver.git//', 'composer/semver');
        yield 'https with uppercase .Git' => $yield('https://github.com/composer/semver.Git', 'composer/semver');
        yield 'https with userinfo' => $yield('https://token@github.com/composer/semver.git', 'composer/semver');
        yield 'https with port' => $yield('https://gitlab.example.com:8443/acme/widget.git', 'acme/widget');
        yield 'https with fragment' => $yield('https://github.com/composer/semver.git#head', 'composer/semver');
        yield 'https with query' => $yield('https://github.com/composer/semver.git?go-get=1', 'composer/semver');
        yield 'scp style' => $yield('git@github.com:composer/semver.git', 'composer/semver');
        yield 'ssh with port' => $yield('ssh://git@github.com:22/composer/semver.git', 'composer/semver');
        yield 'git protocol' => $yield('git://github.com/composer/semver.git', 'composer/semver');
        yield 'file url' => $yield('file:///tmp/some-origin', 'tmp/some-origin');
        yield 'gitlab subgroup keeps the last two' => $yield('https://gitlab.com/group/sub/widget.git', 'sub/widget');

        yield 'single segment is refused' => $yield('ssh://user@gerrit.example.com:29418/myproject.git', null, 'no vendor segment');
        yield 'single segment file url is refused' => $yield('file:///solo', null, 'no vendor segment');
        yield 'empty path is refused' => $yield('https://github.com/', null, 'Unable to derive a target path');
        yield 'dot segment names its url' => $yield('https://github.com/composer/.semver.git', null, 'https://github.com/composer/.semver.git');
    }

    #[Test]
    public function test_normalize_clone_url_round_trips_between_protocols(): void
    {
        $scp = 'git@github.com:composer/semver.git';
        $https = 'https://github.com/composer/semver.git';

        $this->assertSame($https, normalizeCloneUrl($scp, 'https'));
        $this->assertSame($scp, normalizeCloneUrl($https, 'ssh'));
        $this->assertSame($scp, normalizeCloneUrl($scp, 'preserve'));
    }
}

define('CORPUS_INCLUDED', true);
require_once dirname(__DIR__, 3).'/bin/corpus';
