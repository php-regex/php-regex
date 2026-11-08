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

namespace PHPRegex\Tests\Unit\Cache;

use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetStore;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Tests\Support\CacheOwnerFunctionOverrides;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CacheOwnershipUnavailableTest extends TestCase
{
    /**
     * With ext-posix, a directory another user owns is not trusted either:
     * where fileowner() answers another uid, both caches leave their files
     * unread; where it answers the running one, the same files load.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    #[RequiresPhpExtension('posix')]
    public function test_existing_caches_are_ignored_when_another_user_owns_them(): void
    {
        $root = sys_get_temp_dir().'/regex-cache-owner-'.bin2hex(random_bytes(6));
        $cache = new FilesystemCache($root.'/trees');
        Regex::create(['cache' => $cache])->parse('/a/');
        $files = glob($root.'/trees/*/*.cache') ?: [];
        $this->assertCount(1, $files);
        $store = new ClassSetStore($root);
        $store->save('u|:\p{Han}', ['set' => CharSet::single(0x41)]);
        $setFiles = glob($store->directory().'/*.set') ?: [];
        $this->assertCount(1, $setFiles);

        try {
            $this->assertInstanceOf(RegexNode::class, $cache->load($files[0]), 'Owned by the running user, the tree loads.');
            $this->assertNotNull($store->load('u|:\p{Han}'), 'Owned by the running user, the set loads.');

            CacheOwnerFunctionOverrides::$anotherOwner = true;

            $this->assertNull($cache->load($files[0]));
            $this->assertNull($store->load('u|:\p{Han}'));
        } finally {
            CacheOwnerFunctionOverrides::reset();
            @unlink($setFiles[0]);
            @rmdir($store->directory());
            @unlink($files[0]);
            @rmdir(\dirname($files[0]));
            @rmdir($root.'/trees');
            @rmdir($root);
        }
    }
}
