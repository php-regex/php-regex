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

namespace PHPRegex\Tests\Unit\Lsp;

use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\LanguageServer\Server;
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The libraries of vendor/ are read for their declarations at "initialize",
 * and again when the editor reports one of their files changed.
 *
 * "/(a/" is the broken pattern: preg_match() refuses it, "missing closing
 * parenthesis at offset 2".
 */
final class ServerLibrariesTest extends TestCase
{
    private const DECLARATIONS = <<<'CODE'
        <?php

        namespace Acme\Support;

        use PHPRegex\Parser\Attribute\RegexPattern;

        final class Str
        {
            public static function matches(string $subject, #[RegexPattern] string $regex): bool
            {
                return true;
            }
        }
        CODE;

    private const CALLS = <<<'CODE'
        <?php

        namespace App\Http;

        use Acme\Support\Str;

        Str::matches($subject, '/(a/');
        CODE;

    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $input;

    private string $root;

    private ?Server $server = null;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        $input = fopen('php://memory', 'r+');
        if (false === $output || false === $input) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->input = $input;

        $this->root = Path::normalize(sys_get_temp_dir()).'/regex-parser-lsp-libraries-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->input);

        self::remove($this->root);
    }

    /**
     * A package installed while the editor runs: Composer rewrites
     * vendor/composer/installed.json, and the libraries are read again.
     */
    #[Test]
    public function test_a_package_installed_later_is_read(): void
    {
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->write('vendor/acme/support/src/Str.php', self::DECLARATIONS);
        $this->write('vendor/composer/installed.json', '{"packages": []}');
        $this->send(['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [['uri' => $this->uri('vendor/composer/installed.json'), 'type' => 2]]]]);

        $this->assertSame([6], $this->diagnosedLines($calls));
    }

    #[Test]
    public function test_libraries_too_large_to_read_whole_are_reported(): void
    {
        $this->write('vendor/a/A.php', '<?php');
        $this->write('vendor/b/B.php', '<?php');

        $this->initialize(new PatternDeclarations(1));

        $warnings = array_filter(
            $this->notifications('window/logMessage'),
            static fn (array $params): bool => 2 === ($params['type'] ?? null) && \is_string($params['message'] ?? null) && str_contains($params['message'], 'vendor/'),
        );
        $this->assertCount(1, $warnings);
        $this->assertSame([['type' => 2, 'message' => 'Functions declaring #[RegexPattern] were read from the first PHP files of vendor/ only.']], $this->notifications('window/showMessage'));
    }

    /**
     * A Composer project whose vendor/ is not on this side of the editor —
     * installed in a container, or not installed yet — has its libraries'
     * declarations silently missing: the user is told, once.
     */
    #[Test]
    public function test_a_composer_project_without_vendor_is_warned_once(): void
    {
        $this->write('composer.json', '{"require": {"acme/support": "^1.0"}}');

        $this->initialize();
        $this->send(['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [['uri' => $this->uri('vendor/composer/installed.json'), 'type' => 2]]]]);

        $shown = $this->notifications('window/showMessage');
        $this->assertCount(1, $shown);
        $this->assertSame(2, $shown[0]['type'] ?? null);
        $this->assertSame(
            'vendor/ is not in the workspace, though composer.json is: the functions its libraries declare with #[RegexPattern] are not read, and the patterns passed to them are not checked. Install the dependencies where the editor sees them, the host and not only a container.',
            $shown[0]['message'] ?? null,
        );
    }

    #[Test]
    public function test_a_composer_project_with_vendor_is_not_warned(): void
    {
        $this->write('composer.json', '{"require": {"acme/support": "^1.0"}}');
        $this->write('vendor/composer/installed.json', '{"packages": []}');

        $this->initialize();

        $this->assertSame([], $this->notifications('window/showMessage'));
    }

    /**
     * The platform requirements install nothing: without a package to
     * install, no library declares anything.
     */
    #[Test]
    public function test_a_composer_project_requiring_only_the_platform_is_not_warned(): void
    {
        $this->write('composer.json', '{"require": {"php": ">=8.1", "ext-mbstring": "*", "lib-pcre": "*", "composer-runtime-api": "^2"}, "require-dev": {}}');

        $this->initialize();

        $this->assertSame([], $this->notifications('window/showMessage'));
    }

    #[Test]
    public function test_a_composer_project_requiring_a_package_only_for_development_is_warned(): void
    {
        $this->write('composer.json', '{"require": {"php": ">=8.1"}, "require-dev": {"acme/support": "^1.0"}}');

        $this->initialize();

        $this->assertCount(1, $this->notifications('window/showMessage'));
    }

    #[Test]
    public function test_a_composer_json_that_cannot_be_read_is_not_warned_for_vendor(): void
    {
        $this->write('composer.json', '{"require": ');

        $this->initialize();

        $this->assertSame([], $this->notifications('window/showMessage'));
    }

    #[Test]
    public function test_a_workspace_without_composer_json_is_not_warned_for_vendor(): void
    {
        $this->initialize();

        $this->assertSame([], $this->notifications('window/showMessage'));
    }

    /**
     * A PHP file of vendor/ the editor reports changed is not read per
     * event: composer's manifest, watched on its own, triggers the
     * authoritative rescan, and no second read follows a composer update.
     */
    #[Test]
    public function test_a_vendor_php_file_event_is_not_read(): void
    {
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->write('vendor/acme/support/src/Str.php', self::DECLARATIONS);
        $this->send(['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [['uri' => $this->uri('vendor/acme/support/src/Str.php'), 'type' => 2]]]]);

        $this->assertSame([], $this->diagnosedLines($calls), 'A vendor file was read per event.');
        $this->assertSame(1, $this->publishesFor($calls), 'The document was re-published for a vendor file event.');
    }

    /**
     * A vendor subtree the workspace's regex.json names among its paths is
     * workspace, not library: its watched events are read like any
     * configured path's.
     */
    #[Test]
    public function test_a_vendor_path_configured_as_workspace_keeps_its_events(): void
    {
        $this->write('regex.json', '{"paths": ["src", "vendor/lib"], "exclude": ["node_modules"]}');
        $this->write('vendor/lib/Str.php', self::DECLARATIONS);
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([6], $this->diagnosedLines($calls));

        $this->write('vendor/lib/Str.php', '<?php // the declaration is gone');
        $this->send(['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [['uri' => $this->uri('vendor/lib/Str.php'), 'type' => 2]]]]);

        $this->assertSame([], $this->diagnosedLines($calls), 'The configured vendor path was not re-read.');
    }

    /**
     * The manifest the rescan reads is watched on its own: a client that
     * offers dynamic registration is asked for it, beside the workspace's
     * PHP files.
     */
    #[Test]
    public function test_the_installed_json_watcher_is_registered_when_the_client_offers_it(): void
    {
        $this->server = new Server(null, $this->input, transport: new Transport($this->output));
        $this->send(
            ['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]],
            ['method' => 'initialized', 'params' => []],
        );

        $globs = [];
        foreach ($this->registrations() as $request) {
            $params = $request['params'] ?? null;
            $this->assertIsArray($params);
            $entries = $params['registrations'] ?? null;
            $this->assertIsArray($entries);
            $this->assertIsArray($entries[0] ?? null);
            $this->assertSame('workspace/didChangeWatchedFiles', $entries[0]['method'] ?? null);

            $options = $entries[0]['registerOptions'] ?? null;
            $this->assertIsArray($options);
            $watchers = $options['watchers'] ?? [];
            $this->assertIsArray($watchers);
            foreach ($watchers as $watcher) {
                $this->assertIsArray($watcher);
                $globs[] = $watcher['globPattern'] ?? null;
            }
        }

        $this->assertContains('vendor/composer/installed.json', $globs);
        $this->assertContains('**/*.php', $globs);
    }

    /**
     * The client groups the files it reports: one notification, whatever it
     * holds, refreshes the declarations once and re-publishes each open
     * document once.
     */
    #[Test]
    public function test_two_file_events_in_one_notification_refresh_once(): void
    {
        $calls = $this->uri('src/Http/Controller.php');
        $this->write('src/Str.php', self::DECLARATIONS);

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([6], $this->diagnosedLines($calls));

        // One notification: the declaration removed, a file created.
        $this->write('src/Str.php', "<?php\n\n// no declaration anymore\n");
        $this->write('src/Extra.php', "<?php\n\n// nothing declared\n");
        $this->send(['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [
            ['uri' => $this->uri('src/Str.php'), 'type' => 2],
            ['uri' => $this->uri('src/Extra.php'), 'type' => 1],
        ]]]);

        $this->assertSame([], $this->diagnosedLines($calls));
        $this->assertSame(2, $this->publishesFor($calls), 'The burst refreshed the document once, not once per event.');
    }

    /**
     * regex.json is no watched file: an event for it changes nothing, and
     * the libraries are not read again behind it.
     */
    #[Test]
    public function test_an_event_for_regex_json_changes_nothing(): void
    {
        $calls = $this->uri('src/Http/Controller.php');
        $this->write('regex.json', '{}');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->write('vendor/acme/support/src/Str.php', self::DECLARATIONS);
        $this->send(['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [['uri' => $this->uri('regex.json'), 'type' => 2]]]]);

        $this->assertSame([], $this->diagnosedLines($calls), 'The libraries were read again behind a regex.json event.');
        $this->assertSame(1, $this->publishesFor($calls));
    }

    /**
     * How many publishDiagnostics batches a document received.
     */
    private function publishesFor(string $uri): int
    {
        $count = 0;
        foreach ($this->notifications('textDocument/publishDiagnostics') as $params) {
            if ($uri === ($params['uri'] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The client/registerCapability requests on the wire.
     *
     * @return list<non-empty-array<mixed>>
     */
    private function registrations(): array
    {
        rewind($this->output);
        $written = (string) stream_get_contents($this->output);

        $requests = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $written) ?: [] as $body) {
            $message = json_decode($body, true);
            if (\is_array($message) && 'client/registerCapability' === ($message['method'] ?? null)) {
                $requests[] = $message;
            }
        }

        return $requests;
    }

    private function initialize(?PatternDeclarations $declarations = null): void
    {
        $this->server = new Server(null, $this->input, $declarations, transport: new Transport($this->output));
        $this->send(
            ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => FileUri::of($this->root)]],
            ['method' => 'initialized', 'params' => []],
        );
    }

    /**
     * Hands the server the messages and lets it read them, as many times as
     * a test needs: the input stream is written past what it has read.
     *
     * @param array<string, mixed> ...$messages
     */
    private function send(array ...$messages): void
    {
        $this->assertInstanceOf(Server::class, $this->server);

        $start = ftell($this->input);
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($this->input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        fseek($this->input, (int) $start);

        $this->server->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function didOpen(string $uri, string $text): array
    {
        return ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
            'uri' => $uri,
            'languageId' => 'php',
            'version' => 1,
            'text' => $text,
        ]]];
    }

    private function uri(string $relative): string
    {
        return FileUri::of($this->root.'/'.$relative);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * The lines of the diagnostics last published for a document.
     *
     * @return list<int>
     */
    private function diagnosedLines(string $uri): array
    {
        $published = null;
        foreach ($this->notifications('textDocument/publishDiagnostics') as $params) {
            if ($uri === ($params['uri'] ?? null) && \is_array($params['diagnostics'] ?? null)) {
                $published = $params['diagnostics'];
            }
        }
        $this->assertIsArray($published, 'The server published no diagnostics for '.$uri.'.');

        $lines = [];
        foreach ($published as $diagnostic) {
            $this->assertIsArray($diagnostic);
            $this->assertSame('php-regex', $diagnostic['source'] ?? null);
            $range = $diagnostic['range'] ?? null;
            $this->assertIsArray($range);
            $start = $range['start'] ?? null;
            $this->assertIsArray($start);
            $line = $start['line'] ?? null;
            $this->assertIsInt($line);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(string $method): array
    {
        rewind($this->output);
        $written = (string) stream_get_contents($this->output);

        $found = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $written) ?: [] as $body) {
            $message = json_decode($body, true);
            if (\is_array($message) && $method === ($message['method'] ?? null) && \is_array($message['params'] ?? null)) {
                /** @var array<string, mixed> $params */
                $params = $message['params'];
                $found[] = $params;
            }
        }

        return $found;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::remove($path.'/'.$entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
