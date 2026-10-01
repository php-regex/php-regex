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

namespace PHPRegex\LanguageServer\Handler;

use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\Toolkit\Regex;

/**
 * Handles the LSP initialize request.
 */
final class InitializeHandler
{
    /**
     * Handle the initialize request.
     */
    public function handle(Message $message): void
    {
        if (null === $message->id) {
            return;
        }

        $capabilities = [
            'textDocumentSync' => [
                'openClose' => true,
                'change' => 1, // Full sync
                'save' => ['includeText' => true],
            ],
            'hoverProvider' => true,
            'codeActionProvider' => [
                'codeActionKinds' => [
                    'quickfix',
                    'refactor.rewrite',
                ],
            ],
            'completionProvider' => [
                'triggerCharacters' => ['\\', '[', '(', '/'],
                'resolveProvider' => false,
            ],
        ];

        $serverInfo = [
            'name' => 'php-regex-lsp',
            'version' => Regex::VERSION,
        ];

        Response::success($message->id, [
            'capabilities' => $capabilities,
            'serverInfo' => $serverInfo,
        ]);
    }
}
