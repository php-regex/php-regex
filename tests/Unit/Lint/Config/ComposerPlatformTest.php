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

namespace PHPRegex\Tests\Unit\Lint\Config;

use PHPRegex\Linter\Config\ComposerPlatform;
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the manifest is read from: the COMPOSER variable names it, as
 * Composer reads it, relative to the project unless it is absolute or
 * starts with a Windows drive. A name kept whole is not looked for under
 * the project, which is how the drive letter shows on any system.
 */
final class ComposerPlatformTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/php-regex-composer-platform-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/elsewhere', 0o777, true);
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    #[Test]
    public function test_a_relative_name_is_read_from_the_project_directory(): void
    {
        file_put_contents($this->dir.'/custom.json', '{"require": {"php": ">=8.2"}}');
        $notices = [];

        $read = ComposerPlatform::read($this->dir, ['COMPOSER' => 'custom.json'], $notices);

        $this->assertNotNull($read);
        $this->assertSame(80200, $read[0]);
        $this->assertSame([], $notices);
    }

    #[Test]
    public function test_an_absolute_name_is_read_as_it_is(): void
    {
        file_put_contents($this->dir.'/elsewhere/composer.json', '{"require": {"php": ">=8.3"}}');
        $notices = [];

        $read = ComposerPlatform::read($this->dir, ['COMPOSER' => $this->dir.'/elsewhere/composer.json'], $notices);

        $this->assertNotNull($read);
        $this->assertSame(80300, $read[0]);
        $this->assertSame([], $notices);
    }

    /**
     * @return iterable<string, array{name: string, keptWhole: bool}>
     */
    public static function provideDriveLikeNames(): iterable
    {
        yield 'an upper-case drive' => ['name' => 'C:/x/composer.json', 'keptWhole' => true];
        yield 'a lower-case drive' => ['name' => 'c:/x/composer.json', 'keptWhole' => true];
        yield 'a drive with a backslash' => ['name' => 'C:\\x\\composer.json', 'keptWhole' => true];
        yield 'a digit is no drive' => ['name' => '1:/x/composer.json', 'keptWhole' => false];
        yield 'a colon first is no drive' => ['name' => ':C/x/composer.json', 'keptWhole' => false];
        yield 'a letter with no colon is no drive' => ['name' => 'Cx/x/composer.json', 'keptWhole' => false];
    }

    #[Test]
    #[DataProvider('provideDriveLikeNames')]
    // A colon is no character of a name on Windows: the file under the project cannot exist there, and the absolute-path test reads a drive name.
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_a_name_starting_with_a_drive_is_kept_whole_and_any_other_is_joined_to_the_project(string $name, bool $keptWhole): void
    {
        // the same name as a path under the project: read when joined, missed when kept whole
        $under = $this->dir.'/'.Path::normalize($name);
        mkdir(\dirname($under), 0o777, true);
        file_put_contents($under, '{"require": {"php": ">=8.3"}}');
        $notices = [];

        $read = ComposerPlatform::read($this->dir, ['COMPOSER' => $name], $notices);

        if ($keptWhole) {
            $this->assertNull($read);
            $this->assertSame(['No '.$name.' in '.$this->dir.': judging for the running PHP.'], $notices);
        } else {
            $this->assertNotNull($read);
            $this->assertSame(80300, $read[0]);
            $this->assertSame([], $notices);
        }
    }

    #[Test]
    public function test_a_name_that_is_no_file_is_a_notice_and_no_floor(): void
    {
        $notices = [];

        $read = ComposerPlatform::read($this->dir, ['COMPOSER' => 'missing.json'], $notices);

        $this->assertNull($read);
        $this->assertSame(['No missing.json in '.$this->dir.': judging for the running PHP.'], $notices);
    }
}
