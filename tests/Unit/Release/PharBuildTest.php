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

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The phar is built with its files stored as they are. `regex lint --jobs`
 * forks its workers, and forked processes that load classes from a phar
 * whose entries are gzip-compressed read each other's half-inflated bytes:
 * a worker dies on "Unterminated comment" in a file it never wrote (8 of 8
 * children on PHP 8.5, Linux; 7 of 8 on PHP 8.4, macOS). Stored entries
 * load cleanly from every child.
 */
final class PharBuildTest extends TestCase
{
    #[Test]
    public function test_the_phar_stores_its_files_uncompressed(): void
    {
        $config = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/box.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($config);

        $this->assertSame('NONE', $config['compression'] ?? 'NONE');
    }
}
