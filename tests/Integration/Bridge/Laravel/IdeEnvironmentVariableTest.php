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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Orchestra\Testbench\Attributes\WithEnv;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ide default of config/php-regex.php reads one environment variable,
 * PHP_REGEX_IDE, and falls back to APP_EDITOR when it is not bound.
 */
final class IdeEnvironmentVariableTest extends LaravelTestCase
{
    #[Test]
    #[WithEnv('PHP_REGEX_IDE', 'vscode')]
    public function test_the_ide_default_reads_php_regex_ide(): void
    {
        $this->assertSame('vscode', config('php-regex.ide'));
    }

    #[Test]
    #[WithEnv('PHP_REGEX_IDE', 'phpstorm://open?file=%f&line=%l')]
    public function test_the_ide_default_reads_a_url_template(): void
    {
        $this->assertSame('phpstorm://open?file=%f&line=%l', config('php-regex.ide'));
    }

    #[Test]
    #[WithEnv('REGEX_PARSER_IDE', 'sublime')]
    public function test_the_ide_default_no_longer_reads_regex_parser_ide(): void
    {
        // The 1.x variable is nobody's fallback: without APP_EDITOR, the
        // default is null, never the value the old name carries.
        $this->assertNull(config('php-regex.ide'));
    }

    #[Test]
    #[WithEnv('REGEX_PARSER_IDE', 'sublime')]
    #[WithEnv('APP_EDITOR', 'textmate')]
    public function test_the_ide_default_falls_back_to_app_editor(): void
    {
        $this->assertSame('textmate', config('php-regex.ide'));
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
