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

namespace PhpRegex\Tests\Unit\Lsp\Document;

use PhpRegex\LanguageServer\Document\DocumentManager;
use PhpRegex\LanguageServer\Document\RegexFinder;
use PhpRegex\LanguageServer\Document\RegexOccurrence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentManagerTest extends TestCase
{
    private DocumentManager $manager;

    protected function setUp(): void
    {
        $this->manager = new DocumentManager(new RegexFinder());
    }

    #[Test]
    public function test_open_stores_document(): void
    {
        $uri = 'file:///test.php';
        $content = "<?php\necho 'hello';";

        $this->manager->open($uri, $content);

        $this->assertTrue($this->manager->isOpen($uri));
        $this->assertSame($content, $this->manager->getContent($uri));
    }

    #[Test]
    public function test_close_removes_document(): void
    {
        $uri = 'file:///test.php';
        $this->manager->open($uri, "<?php\necho 'hello';");

        $this->manager->close($uri);

        $this->assertFalse($this->manager->isOpen($uri));
        $this->assertNull($this->manager->getContent($uri));
    }

    #[Test]
    public function test_update_replaces_content(): void
    {
        $uri = 'file:///test.php';
        $this->manager->open($uri, "<?php\necho 'v1';");
        $this->manager->update($uri, "<?php\necho 'v2';");

        $this->assertSame("<?php\necho 'v2';", $this->manager->getContent($uri));
    }

    #[Test]
    public function test_get_occurrences_returns_patterns(): void
    {
        $uri = 'file:///test.php';
        $this->manager->open($uri, "<?php\npreg_match('/\\w+/', \$t);");

        $occurrences = $this->manager->getOccurrences($uri);

        $this->assertCount(1, $occurrences);
        $this->assertSame('/\\w+/', $occurrences[0]->pattern);
    }

    #[Test]
    public function test_get_occurrences_updates_on_document_change(): void
    {
        $uri = 'file:///test.php';
        $this->manager->open($uri, "<?php\npreg_match('/foo/', \$t);");

        $this->assertSame('/foo/', $this->manager->getOccurrences($uri)[0]->pattern);

        $this->manager->update($uri, "<?php\npreg_match('/bar/', \$t);");

        $this->assertSame('/bar/', $this->manager->getOccurrences($uri)[0]->pattern);
    }

    #[Test]
    public function test_get_occurrence_at_position_returns_match(): void
    {
        $uri = 'file:///test.php';
        $this->manager->open($uri, "<?php\npreg_match('/test/', \$t);");

        // Position inside the pattern
        $occurrence = $this->manager->getOccurrenceAtPosition($uri, 1, 14);

        $this->assertInstanceOf(RegexOccurrence::class, $occurrence);
        $this->assertSame('/test/', $occurrence->pattern);
    }

    #[Test]
    public function test_get_occurrence_at_position_returns_null_outside_pattern(): void
    {
        $uri = 'file:///test.php';
        $this->manager->open($uri, "<?php\npreg_match('/test/', \$t);");

        // Position before the pattern
        $occurrence = $this->manager->getOccurrenceAtPosition($uri, 1, 5);

        $this->assertNotInstanceOf(RegexOccurrence::class, $occurrence);
    }

    #[Test]
    public function test_get_occurrence_at_position_returns_null_for_closed_document(): void
    {
        $uri = 'file:///test.php';

        $occurrence = $this->manager->getOccurrenceAtPosition($uri, 1, 14);

        $this->assertNotInstanceOf(RegexOccurrence::class, $occurrence);
    }

    #[Test]
    public function test_get_occurrences_returns_empty_for_unknown_uri(): void
    {
        $occurrences = $this->manager->getOccurrences('file:///unknown.php');

        $this->assertSame([], $occurrences);
    }
}
