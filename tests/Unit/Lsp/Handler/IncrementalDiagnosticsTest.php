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
use PHPRegex\LanguageServer\Handler\TextDocumentHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The language server keeps what it found in each pattern it analysed, for
 * the server's targets and its lint and ReDoS settings: an edit analyses the
 * patterns whose text it changed, and places the findings of the others
 * where they now stand. What it publishes is what a server opening the
 * edited text publishes.
 */
final class IncrementalDiagnosticsTest extends TestCase
{
    private const URI = 'file:///a.php';

    /**
     * Each step: the whole text after the edit.
     */
    private const EDITS = [
        "<?php\npreg_match('/a+(a*)b/', \$s);\npreg_match('/(?:a|b)*c/', \$s);\npreg_match('/[a-z]+[a-z]/', \$s);\n",
        // a line above every pattern: all of them move
        "<?php\n// moved\npreg_match('/a+(a*)b/', \$s);\npreg_match('/(?:a|b)*c/', \$s);\npreg_match('/[a-z]+[a-z]/', \$s);\n",
        // one pattern changed, to an invalid one
        "<?php\n// moved\npreg_match('/a+(a*)b/', \$s);\npreg_match('/(?:a|b*c/', \$s);\npreg_match('/[a-z]+[a-z]/', \$s);\n",
        // the same pattern twice, one of them silenced
        "<?php\n// moved\npreg_match('/a+(a*)b/', \$s);\npreg_match('/(?:a|b*c/', \$s);\n// @regex-ignore-next-line\npreg_match('/a+(a*)b/', \$s);\n  preg_match('/a+(a*)b/', \$s);\n",
        // a pattern gone, a double-quoted one with a raw tab spelled \\t
        "<?php\npreg_match(\"/x\\t+\\\\d/\", \$s);\npreg_match('/[a-z]+[a-z]/', \$s);\n",
    ];

    #[Test]
    public function test_an_edit_analyses_only_the_patterns_it_changed(): void
    {
        $handler = new TextDocumentHandler(new DocumentManager(new RegexFinder()), Regex::create(['cache' => null]));
        $this->publish($handler, self::EDITS[0], true);
        $this->assertSame(3, self::kept($handler));

        $this->publish($handler, self::EDITS[1], false);
        $this->assertSame(3, self::kept($handler), 'Moving the patterns analysed them again.');

        $this->publish($handler, self::EDITS[2], false);
        $this->assertSame(4, self::kept($handler));
    }

    #[Test]
    public function test_the_server_keeps_the_latest_4096_patterns(): void
    {
        $handler = new TextDocumentHandler(new DocumentManager(new RegexFinder()), Regex::create(['cache' => null]));
        $lines = '';
        for ($i = 0; $i <= 4096; $i++) {
            $lines .= "preg_match('/a".$i."/', \$s);\n";
        }

        $this->publish($handler, "<?php\n".$lines, true);

        $this->assertSame(4096, self::kept($handler));
    }

    #[Test]
    public function test_what_is_published_after_each_edit_is_what_a_fresh_server_publishes(): void
    {
        $published = 0;
        foreach ([[RedosMode::Off, []], [RedosMode::Theoretical, ['quantifier.uselessLazy' => true]]] as [$redos, $rules]) {
            $handler = new TextDocumentHandler(new DocumentManager(new RegexFinder()), Regex::create(['cache' => null]), $rules, $redos, RedosSeverity::Low);
            foreach (self::EDITS as $step => $text) {
                $incremental = $this->publish($handler, $text, 0 === $step);
                $fresh = $this->publish(new TextDocumentHandler(new DocumentManager(new RegexFinder()), Regex::create(['cache' => null]), $rules, $redos, RedosSeverity::Low), $text, true);

                $this->assertSame($fresh, $incremental, 'Step '.$step);
                $published += \count($fresh);
            }
        }

        $this->assertGreaterThan(10, $published);
    }

    /**
     * The diagnostics the handler publishes for the text, opened or changed.
     *
     * @return list<mixed>
     */
    private function publish(TextDocumentHandler $handler, string $text, bool $open): array
    {
        $published = [];
        Response::observeWith(static function (string $json) use (&$published): void {
            $published[] = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        });
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            $document = ['uri' => self::URI, 'version' => 1];
            if ($open) {
                $handler->didOpen(new Message(jsonrpc: '2.0', method: 'textDocument/didOpen', id: null, params: ['textDocument' => $document + ['text' => $text]]));
            } else {
                $handler->didChange(new Message(jsonrpc: '2.0', method: 'textDocument/didChange', id: null, params: ['textDocument' => $document, 'contentChanges' => [['text' => $text]]]));
            }
        } finally {
            Response::writeTo(null);
            Response::observeWith(null);
        }

        $diagnostics = [];
        foreach ($published as $message) {
            $params = \is_array($message) && \is_array($message['params'] ?? null) ? $message['params'] : [];
            if (self::URI === ($params['uri'] ?? null) && \is_array($params['diagnostics'] ?? null)) {
                $diagnostics = array_values($params['diagnostics']);
            }
        }

        return $diagnostics;
    }

    private static function kept(TextDocumentHandler $handler): int
    {
        $kept = (new \ReflectionProperty($handler, 'analyses'))->getValue($handler);
        self::assertIsArray($kept);

        return \count($kept);
    }
}
