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

namespace PHPRegex\Tests\Unit\Lsp\Handler;

use PHPRegex\LanguageServer\Handler\InitializeHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InitializeHandlerTest extends TestCase
{
    private InitializeHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new InitializeHandler(new Transport());
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
        $this->expectNotToPerformAssertions();
    }
}
