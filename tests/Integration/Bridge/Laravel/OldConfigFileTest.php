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

use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;

/**
 * A config/regex-parser.php published by 1.x is no longer read: the provider
 * says so, once per registration, and names the file that replaced it.
 */
final class OldConfigFileTest extends TestCase
{
    public function test_an_old_config_file_is_reported(): void
    {
        $this->app['config']->set('regex-parser', ['max_pattern_length' => 10]);

        $deprecations = $this->deprecationsWhile(fn () => (new PHPRegexServiceProvider($this->app))->register());

        $this->assertSame(
            ['config/regex-parser.php is no longer read since 2.0: move its settings to config/php-regex.php (php artisan vendor:publish --tag=php-regex-config).'],
            $deprecations,
        );
        $this->assertNotSame(10, $this->app['config']->get('php-regex.max_pattern_length'));
    }

    public function test_no_old_config_file_reports_nothing(): void
    {
        $deprecations = $this->deprecationsWhile(fn () => (new PHPRegexServiceProvider($this->app))->register());

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
