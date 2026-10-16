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

use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Toolkit\Regex;

/**
 * A config/regex-parser.php published by 1.x is no longer read, and 2.0.0
 * says nothing about it either: Laravel keeps loading the file, the provider
 * keeps ignoring it, and every php-regex.* default stays the 2.0 one.
 */
final class OldConfigFileTest extends LaravelTestCase
{
    public function test_an_old_config_file_is_silently_ignored(): void
    {
        self::configOf($this->application())->set('regex-parser', ['max_pattern_length' => 10]);

        $deprecations = $this->deprecationsWhile(fn () => (new PHPRegexServiceProvider($this->application()))->register());

        $this->assertSame([], $deprecations, 'A 1.x config file must not raise a deprecation any more.');
        $this->assertSame(Regex::DEFAULT_MAX_PATTERN_LENGTH, self::configOf($this->application())->get('php-regex.max_pattern_length'));
    }

    public function test_no_old_config_file_reports_nothing(): void
    {
        $deprecations = $this->deprecationsWhile(fn () => (new PHPRegexServiceProvider($this->application()))->register());

        $this->assertSame([], $deprecations);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @return list<string>
     */
    private function deprecationsWhile(\Closure $run): array
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            if (\E_USER_DEPRECATED === $level) {
                $deprecations[] = $message;
            }

            return true;
        });

        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }
}
