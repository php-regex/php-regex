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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A page no other page sends to is a page a reader, and a search engine,
 * never finds. The pages people look a phrase up on — the cheat sheet, the
 * guides, the recipes of the cookbook, the message PHP prints — are each
 * reached from the list that holds their kind: the index of the guides, the
 * cheat sheet for a message, the map of the documentation, and the index a
 * model reads.
 */
#[Group('documentation')]
final class LearnPagesTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    private const SITE = 'https://php-regex.com';

    /**
     * @return iterable<string, array{file: string, address: string}>
     */
    public static function provideLearnPages(): iterable
    {
        $pages = [self::DOCS.'cheat-sheet.md', ...\glob(self::DOCS.'guides/*.md') ?: [], ...\glob(self::DOCS.'cookbook/*.md') ?: []];
        foreach ($pages as $file) {
            $name = \basename($file, '.md');
            if ('index' === $name) {
                continue;
            }

            $folder = \dirname(\substr($file, \strlen(self::DOCS)));
            yield $file => ['file' => $file, 'address' => ('.' === $folder ? '' : '/'.$folder).'/'.$name.'/'];
        }
    }

    /**
     * @return iterable<string, array{file: string, address: string}>
     */
    public static function provideGuides(): iterable
    {
        foreach (self::provideLearnPages() as $page) {
            if (str_starts_with($page['address'], '/guides/')) {
                yield $page['file'] => $page;
            }
        }
    }

    /**
     * @return iterable<string, array{file: string}>
     */
    public static function provideMessages(): iterable
    {
        foreach (\glob(self::DOCS.'reference/diagnostics/*.md') ?: [] as $file) {
            yield $file => ['file' => $file];
        }
    }

    #[Test]
    #[DataProvider('provideLearnPages')]
    public function test_a_page_people_look_a_phrase_up_on_is_indexed_for_models(string $file, string $address): void
    {
        $this->assertStringContainsString(']('.self::SITE.$address.')', (string) file_get_contents(self::DOCS.'llms.txt'), $file.' is in the index a model reads.');
    }

    #[Test]
    #[DataProvider('provideGuides')]
    public function test_the_index_of_the_guides_lists_every_guide(string $file, string $address): void
    {
        // A link of the source names the file: the build turns it into the address of the guide.
        $this->assertStringContainsString('('.\basename($address, '/').'.md)', (string) file_get_contents(self::DOCS.'guides/index.md'), $file.' is reached from the index of the guides.');
    }

    #[Test]
    #[DataProvider('provideMessages')]
    public function test_the_cheat_sheet_leads_to_the_page_of_every_message_php_prints(string $file): void
    {
        $this->assertStringContainsString('](reference/diagnostics/'.\basename($file, '').')', (string) file_get_contents(self::DOCS.'cheat-sheet.md'), $file.' is reached from the cheat sheet.');
    }

    #[Test]
    public function test_the_map_of_the_documentation_sends_to_the_cheat_sheet_and_the_cookbook(): void
    {
        $map = (string) file_get_contents(self::DOCS.'README.md');

        foreach (['/cheat-sheet/', '/cookbook/'] as $address) {
            $this->assertStringContainsString(']('.$address.')', $map, 'The map sends to '.$address.'.');
        }
    }

    #[Test]
    public function test_the_guide_that_introduces_a_function_and_the_modifiers_sends_to_the_pages_that_go_deeper(): void
    {
        $guide = (string) file_get_contents(self::DOCS.'guides/regex-in-php.md');

        $this->assertStringContainsString('](php-regex-modifiers.md)', $guide, 'The modifiers send to their page.');
        $this->assertStringContainsString('](preg-replace.md)', $guide, 'preg_replace() sends to its page.');
    }
}
