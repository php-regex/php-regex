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

use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Handler\CompletionHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompletionHandlerTest extends TestCase
{
    private DocumentManager $documents;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager(new RegexFinder());
        $handler = new CompletionHandler($this->documents);
    }

    #[Test]
    public function test_handler_can_be_instantiated(): void
    {
        $handler = new CompletionHandler($this->documents);

        $this->assertInstanceOf(CompletionHandler::class, $handler);
    }

    #[Test]
    public function test_handler_requires_document_manager(): void
    {
        $documents = new DocumentManager(new RegexFinder());
        $handler = new CompletionHandler($documents);

        $this->assertInstanceOf(CompletionHandler::class, $handler);
    }
}
