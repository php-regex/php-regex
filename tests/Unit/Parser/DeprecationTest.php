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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Deprecation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A deprecation is raised as symfony/deprecation-contracts raises one, so
 * the deprecation handlers of Symfony and PHPUnit read and group it the
 * same way, without the library depending on that package.
 */
final class DeprecationTest extends TestCase
{
    /**
     * @var list<array{level: int, message: string, silenced: bool}>
     */
    private array $raised = [];

    protected function setUp(): void
    {
        set_error_handler(function (int $level, string $message): bool {
            $this->raised[] = [
                'level' => $level,
                'message' => $message,
                'silenced' => 0 === (error_reporting() & $level),
            ];

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    #[Test]
    public function test_a_deprecation_names_the_package_and_the_version_first(): void
    {
        Deprecation::trigger('php-regex/regex-parser', '2.1', 'The "%s" class is deprecated, use "%s" instead.', 'Old', 'New');

        $this->assertSame([[
            'level' => \E_USER_DEPRECATED,
            'message' => 'Since php-regex/regex-parser 2.1: The "Old" class is deprecated, use "New" instead.',
            'silenced' => true,
        ]], $this->raised);
    }

    #[Test]
    public function test_a_message_without_arguments_is_written_as_given(): void
    {
        Deprecation::trigger('php-regex/regex-linter', '2.3', 'A 100% literal message.');

        $this->assertSame('Since php-regex/regex-linter 2.3: A 100% literal message.', $this->raised[0]['message'] ?? null);
    }

    #[Test]
    public function test_no_package_and_no_version_leave_the_message_bare(): void
    {
        Deprecation::trigger('', '', 'Bare.');

        $this->assertSame('Bare.', $this->raised[0]['message'] ?? null);
    }
}
