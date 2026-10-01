<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lsp\Handler;

use PhpRegex\LanguageServer\Handler\InitializeHandler;
use PhpRegex\LanguageServer\Protocol\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InitializeHandlerTest extends TestCase
{
    private InitializeHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new InitializeHandler();
    }

    #[Test]
    public function test_handle_returns_early_for_notification(): void
    {
        // Notification has no ID
        $message = new Message(
            jsonrpc: '2.0',
            method: 'initialize',
            id: null,
            params: [],
        );

        // Should not throw, just return early
        $this->handler->handle($message);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    #[Test]
    public function test_handler_can_be_instantiated(): void
    {
        $handler = new InitializeHandler();

        $this->assertInstanceOf(InitializeHandler::class, $handler);
    }
}
