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
 *
 * @internal
 */
final class InitializeHandler
{
    private bool $watchedFilesRegistration = false;

    /**
     * Handle the initialize request.
     */
    public function handle(Message $message): void
    {
        // What the client can be asked for: the files the server reads are
        // registered with a client that offers to watch them itself. The
        // protocol's InitializeParams carry them under "capabilities".
        $capabilities = $message->params['capabilities'] ?? null;
        $workspace = \is_array($capabilities) ? ($capabilities['workspace'] ?? null) : null;
        $watched = \is_array($workspace) ? ($workspace['didChangeWatchedFiles'] ?? null) : null;
        $this->watchedFilesRegistration = \is_array($watched) && true === ($watched['dynamicRegistration'] ?? null);

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

    /**
     * Whether the client offers to register the watched files itself.
     */
    public function watchedFilesRegistration(): bool
    {
        return $this->watchedFilesRegistration;
    }
}
