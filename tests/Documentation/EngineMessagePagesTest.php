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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Tests\Support\DocumentationPages;
use PHPRegex\Tests\Support\FrontMatter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A page per message the engine prints: what PHP says when it refuses a
 * pattern, and what it says when a match gives up. Each page names the
 * pattern it opens on. Run on that pattern, the engine prints the message
 * the page documents — on the PHP and the PCRE2 running the suite, or one
 * of the wordings another release gives — and PHPRegex answers it with a
 * code the page names. An example of the body that shows a message shows
 * what PHP prints for it.
 */
#[Group('documentation')]
final class EngineMessagePagesTest extends TestCase
{
    private const PAGES = __DIR__.'/../../docs/reference/diagnostics/';

    private const HUB = __DIR__.'/../../docs/reference/diagnostics.md';

    private const SHEET = __DIR__.'/../../docs/reference/diagnostics-cheatsheet.md';

    /**
     * Where a message comes from, as the index groups the pages: the
     * checks PHP makes before it compiles, the compilation, the match.
     */
    private const FAMILIES = ['The checks of PHP', 'Compilation failed', 'The match'];

    /**
     * What a page says of the engine, under the keys the test reads.
     */
    private const ENGINE_KEYS = ['message', 'also', 'from', 'setting', 'pattern', 'pattern_hex', 'subject', 'subject_hex'];

    #[Test]
    #[DataProvider('provideMessagePages')]
    public function test_the_engine_prints_for_that_pattern_the_message_the_page_documents(string $slug): void
    {
        $engine = $this->engine($slug);
        $answer = self::engineAnswer($engine['pattern'], $engine['subject'], $engine['setting']);
        $this->assertFalse($answer['returned'], $slug.': the engine refuses the pattern, or the match gives up.');

        $printed = 'warning' === $engine['from'] ? $answer['warning'] : $answer['lastError'];
        $accepted = array_filter(
            [$engine['message'], ...$engine['also']],
            static fn (string $wording): bool => self::withoutOffset($wording) === self::withoutOffset($printed),
        );
        $this->assertNotEmpty($accepted, $slug.' documents what the engine prints for the pattern it opens on: '.$printed);
    }

    #[Test]
    #[DataProvider('provideMessagePages')]
    public function test_phpregex_answers_that_pattern_with_a_code_the_page_names(string $slug): void
    {
        $codes = $this->page($slug)['codes'];
        if ([] === $codes) {
            $this->assertSame('preg_last_error_msg', $this->engine($slug)['from'], $slug.' names no code only when a match gives up: a pattern PHP refuses is refused under a code.');

            return;
        }

        $result = Regex::create()->validate($this->engine($slug)['pattern']);
        $this->assertFalse($result->isValid, $slug.': PHPRegex refuses the pattern too.');
        $this->assertContains($result->errorCode?->value, $codes, $slug.' names the code PHPRegex gives the pattern.');
    }

    /**
     * @return iterable<string, array{slug: string}>
     */
    public static function provideMessagePages(): iterable
    {
        foreach (self::slugs() as $slug) {
            yield $slug => ['slug' => $slug];
        }
    }

    #[Test]
    #[DataProvider('provideMessagePages')]
    public function test_a_message_page_carries_what_the_index_and_a_search_result_read(string $slug): void
    {
        $page = $this->page($slug);
        $engine = $this->engine($slug);

        $this->assertSame('/reference/diagnostics/', $page['parent'], $slug.' hangs under the diagnostics reference.');
        $this->assertContains($page['family'], self::FAMILIES, $slug.' names where its message comes from.');
        $this->assertMatchesRegularExpression('/^.{70,160}$/su', $page['description'], $slug.': a description a search result keeps whole.');
        $this->assertLessThanOrEqual(54, mb_strlen($page['title']), $slug.': the title tag stays whole with the site name.');
        $this->assertNotSame('', $engine['message'], $slug.' names the message.');
        foreach ($page['codes'] as $code) {
            $this->assertInstanceOf(ErrorCode::class, ErrorCode::tryFrom($code), $slug.' names a code the library gives: '.$code);
        }
    }

    #[Test]
    public function test_no_two_message_pages_share_a_title_a_description_a_message_or_a_place(): void
    {
        $titles = $descriptions = $messages = $places = [];
        foreach (self::slugs() as $slug) {
            $page = $this->page($slug);

            $this->assertArrayNotHasKey($page['title'], $titles, $slug.' shares its title with '.($titles[$page['title']] ?? '').'.');
            $titles[$page['title']] = $slug;
            $this->assertArrayNotHasKey($page['description'], $descriptions, $slug.' shares its description with '.($descriptions[$page['description']] ?? '').'.');
            $descriptions[$page['description']] = $slug;

            $message = self::withoutOffset($this->engine($slug)['message']);
            $this->assertArrayNotHasKey($message, $messages, $slug.' documents the message '.($messages[$message] ?? '').' documents.');
            $messages[$message] = $slug;

            $place = $page['family'].':'.$page['order'];
            $this->assertArrayNotHasKey($place, $places, $slug.' stands where '.($places[$place] ?? '').' stands.');
            $places[$place] = $slug;
        }
    }

    /**
     * An example that shows what PHP prints shows what PHP prints: the
     * comment under the call carries the warning the engine gives for the
     * pattern of that call. The offset is the engine's to give: a release
     * counts it from another place.
     */
    #[Test]
    #[DataProvider('provideMessagePages')]
    public function test_an_example_that_shows_a_message_shows_what_php_prints(string $slug): void
    {
        $examples = 0;
        foreach (DocumentationPages::phpFences('docs/reference/diagnostics/'.$slug.'.md') as $fence) {
            $lines = preg_split('/\R/', $fence['code']) ?: [];
            foreach ($lines as $index => $line) {
                if (1 !== preg_match('/^\s*(?<call>preg_\w+)\(\s*(?<literal>\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $line, $example)) {
                    continue;
                }

                $comment = null;
                for ($next = $index + 1; isset($lines[$next]); $next++) {
                    if ('' !== trim($lines[$next])) {
                        $comment = $lines[$next];

                        break;
                    }
                }
                if (null === $comment || 1 !== preg_match('/^\s*\/\/\s*(?<call>preg_\w+)\(\): (?<message>.+?)\s*$/', $comment, $shown)) {
                    continue;
                }

                $examples++;
                $at = $slug.', line '.$fence['line'] + $index;
                $this->assertSame($example['call'], $shown['call'], $at.': the comment names the function of the call.');
                $answer = self::engineAnswer(self::literal($example['literal']), '', null);
                $this->assertFalse($answer['returned'], $at.': the engine refuses the pattern.');
                $this->assertSame(self::withoutOffset($shown['message']), self::withoutOffset($answer['warning']), $at.': the comment carries what PHP prints.');
            }
        }

        if ('warning' === $this->engine($slug)['from']) {
            $this->assertGreaterThan(0, $examples, $slug.' shows the message on an example.');
        }
    }

    #[Test]
    public function test_the_reference_lists_the_pages_and_leaves_the_fixes_to_them(): void
    {
        $hub = (string) file_get_contents(self::HUB);

        $this->assertStringContainsString('{%- assign message_pages = site.pages | where_exp: "p", "p.engine" | sort: "engine_order" %}', $hub, 'The reference lists the pages of the messages.');
        $this->assertStringNotContainsString('## Common Fixes', $hub, 'A fix is on the page of its message, or on the cheat sheet that points to it.');
    }

    #[Test]
    public function test_the_cheat_sheet_points_to_the_pages_and_holds_no_code(): void
    {
        $sheet = (string) file_get_contents(self::SHEET);

        $this->assertStringContainsString('{%- assign quick_fixes = site.pages | where_exp: "p", "p.quick_fix" | sort: "quick_fix_order" %}', $sheet, 'The cheat sheet lists the fixes of the pages.');
        $this->assertSame([], DocumentationPages::phpFences('docs/reference/diagnostics-cheatsheet.md'), 'The code of a fix is on the page of its diagnostic.');
    }

    /**
     * A link of a page names the file it goes to, or an address: the site
     * resolves a link to a file into the address of its page. The source
     * of a page is a file beside the pages it links to, and its address a
     * directory of its own: a link written with that address leads one
     * directory off.
     */
    #[Test]
    public function test_every_link_of_a_page_names_a_file_or_an_address(): void
    {
        foreach (self::slugs() as $slug) {
            $source = (string) file_get_contents(self::PAGES.$slug.'.md');
            $this->assertGreaterThan(0, preg_match_all('/\]\(([^)\s]+)\)/', $source, $links), $slug.' links to other pages.');
            $this->assertNotEmpty($links[1]);
            foreach ($links[1] as $href) {
                $this->assertIsString($href);
                if (str_starts_with($href, '/') || 1 === preg_match('~^(?:https?://|mailto:|#)~', $href)) {
                    continue;
                }
                $file = (string) preg_replace('/#.*$/', '', $href);
                $this->assertMatchesRegularExpression('~\.md$~', $file, $slug.': "'.$href.'" names a file the site resolves, not the address of a page.');
                $this->assertFileExists(self::PAGES.$file, $slug.': "'.$href.'" goes to a page that exists.');
            }
        }
    }

    /**
     * The answer of the engine to a pattern, as a script meets it: what
     * the call returns, the warning it raises, and what preg_last_error_msg()
     * says — read before anything else can reset it.
     *
     * @return array{returned: mixed, warning: string, lastError: string}
     */
    private static function engineAnswer(string $pattern, string $subject, ?string $setting): array
    {
        $name = $previous = null;
        if (null !== $setting) {
            [$name, $value] = explode('=', $setting, 2);
            $previous = ini_set($name, $value);
        }

        $warning = '';
        set_error_handler(static function (int $number, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $returned = preg_match($pattern, $subject);
            $lastError = preg_last_error_msg();
        } finally {
            restore_error_handler();
            if (null !== $name && null !== $previous) {
                ini_set($name, $previous);
            }
        }

        return [
            'returned' => $returned,
            'warning' => (string) preg_replace('/ in \S+ on line \d+$/', '', (string) preg_replace('/^preg_match\(\): /', '', $warning)),
            'lastError' => $lastError,
        ];
    }

    /**
     * A PHP string literal of an example, as the engine reads it. A double
     * quoted one interpolates nothing: an example writes its bytes as
     * escapes.
     */
    private static function literal(string $literal): string
    {
        $body = substr($literal, 1, -1);
        if ("'" === $literal[0]) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        return (string) preg_replace_callback(
            '/\\\\(?<escape>x[0-9A-Fa-f]{1,2}|[0-7]{1,3}|[nrtvef\\\\\$"])/',
            static fn (array $match): string => stripcslashes('\\'.$match['escape']),
            (string) preg_replace('~(?<!\\\\)\\\\(?![x0-7nrtvef\\\\\$"])~', '', $body),
        );
    }

    /**
     * A message without the offset the engine gives: a release counts it
     * from another place, and the words are what the page documents.
     */
    private static function withoutOffset(string $message): string
    {
        return (string) preg_replace('/offset \d+/', 'offset N', $message);
    }

    /**
     * The slug of every page of a message: the name of its file.
     *
     * @return list<string>
     */
    private static function slugs(): array
    {
        $pages = glob(self::PAGES.'*.md') ?: [];
        \assert([] !== $pages, 'The diagnostics reference has a page per message of the engine.');

        return array_map(basename(...), $pages, array_fill(0, \count($pages), '.md'));
    }

    /**
     * A page of a message: what the index and a search result read of it.
     *
     * @return array{description: string, title: string, parent: string, family: string, order: int, codes: list<string>}
     */
    private function page(string $slug): array
    {
        $this->assertFileExists(self::PAGES.$slug.'.md');
        $front = FrontMatter::of(self::PAGES.$slug.'.md');

        $description = $front['description'] ?? null;
        $this->assertIsString($description, $slug.' has a description.');
        $title = $front['head_title'] ?? null;
        $this->assertIsString($title, $slug.' names the title search engines read.');
        $parent = $front['parent'] ?? null;
        $this->assertIsString($parent, $slug.' names the page it hangs under.');
        $family = $front['engine_family'] ?? null;
        $this->assertIsString($family, $slug.' names where its message comes from.');
        $order = $front['engine_order'] ?? null;
        $this->assertIsInt($order, $slug.' has its place in its group.');

        $codes = [];
        $declared = $front['codes'] ?? [];
        $this->assertIsArray($declared, $slug.' names its codes as a list.');
        foreach ($declared as $code) {
            $this->assertIsString($code, $slug.' names its codes as words.');
            $codes[] = $code;
        }

        return ['description' => $description, 'title' => $title, 'parent' => $parent, 'family' => $family, 'order' => $order, 'codes' => $codes];
    }

    /**
     * What the page asks the engine: the pattern, the subject, and where
     * PHP says the message. A byte that is not text — a pattern that is
     * not valid UTF-8, a subject that is not — is given in hexadecimal.
     *
     * @return array{message: string, also: list<string>, from: string, setting: ?string, subject: string, pattern: string}
     */
    private function engine(string $slug): array
    {
        $engine = FrontMatter::of(self::PAGES.$slug.'.md')['engine'] ?? null;
        $this->assertIsArray($engine, $slug.' names the pattern it opens on and the message the engine prints.');
        $this->assertSame([], array_keys(array_diff_key($engine, array_flip(self::ENGINE_KEYS))), $slug.' says of the engine only what is read.');

        $message = $engine['message'] ?? null;
        $this->assertIsString($message, $slug.' names the message.');
        $also = [];
        $wordings = $engine['also'] ?? [];
        $this->assertIsArray($wordings, $slug.' names the other wordings as a list.');
        foreach ($wordings as $wording) {
            $this->assertIsString($wording, $slug.' names the other wordings as lines.');
            $also[] = $wording;
        }
        $from = $engine['from'] ?? 'warning';
        $this->assertIsString($from, $slug.' names where PHP says the message.');
        $this->assertContains($from, ['warning', 'preg_last_error_msg'], $slug.' names where PHP says the message.');
        $setting = $engine['setting'] ?? null;
        $this->assertTrue(null === $setting || \is_string($setting), $slug.' names the setting as one line.');

        $pattern = $engine['pattern'] ?? null;
        if (null !== ($engine['pattern_hex'] ?? null)) {
            $this->assertNull($pattern, $slug.' names its pattern once.');
            $this->assertIsString($engine['pattern_hex'], $slug.' gives its pattern in hexadecimal.');
            $this->assertDoesNotMatchRegularExpression('/[^0-9A-F]/', $engine['pattern_hex'], $slug.' writes its pattern in hexadecimal.');
            $pattern = (string) hex2bin($engine['pattern_hex']);
        }
        $this->assertIsString($pattern, $slug.' names the pattern it opens on.');

        $subject = $engine['subject'] ?? '';
        if (null !== ($engine['subject_hex'] ?? null)) {
            $this->assertIsString($engine['subject_hex'], $slug.' gives its subject in hexadecimal.');
            $this->assertDoesNotMatchRegularExpression('/[^0-9A-F]/', $engine['subject_hex'], $slug.' writes its subject in hexadecimal.');
            $subject = (string) hex2bin($engine['subject_hex']);
        }
        $this->assertIsString($subject, $slug.' names its subject as one line.');

        return ['message' => $message, 'also' => $also, 'from' => $from, 'setting' => $setting, 'subject' => $subject, 'pattern' => $pattern];
    }
}
