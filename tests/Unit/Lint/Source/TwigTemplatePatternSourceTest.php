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

namespace PHPRegex\Tests\Unit\Lint\Source;

use PHPRegex\Linter\Extraction\IgnoreMarker;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\TwigTemplatePatternSource;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The source reads the literal right operand of Twig's "matches" operator,
 * which Twig hands to preg_match() as it is, out of the templates under the
 * linted paths.
 */
final class TwigTemplatePatternSourceTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_the_source_is_named_twig_and_always_supported(): void
    {
        $source = new TwigTemplatePatternSource();

        $this->assertSame('twig', $source->getName());
        $this->assertTrue($source->isSupported());
        $this->assertSame([], $source->extract(new PatternSourceContext([], [])));
    }

    /**
     * @param list<array{string, string}> $expected each pattern and the text, found once in the template,
     *                                              that starts at its opening quote
     */
    #[Test]
    #[DataProvider('provideTemplates')]
    public function test_the_literal_operand_of_matches_is_read_where_its_quote_opens(string $template, array $expected): void
    {
        $wanted = [];
        foreach ($expected as [$pattern, $at]) {
            $offset = strpos($template, $at);
            $this->assertNotFalse($offset, $at);
            $this->assertSame($offset, strrpos($template, $at), $at.' is found more than once');
            $lineStart = strrpos(substr($template, 0, $offset), "\n");
            $wanted[] = [$pattern, 1 + substr_count($template, "\n", 0, $offset), $offset - (false === $lineStart ? -1 : $lineStart)];
        }

        $this->assertSame($wanted, self::read(TwigTemplatePatternSource::read($template, 'page.html.twig')));
    }

    /**
     * @return iterable<string, array{template: string, expected: list<array{string, string}>}>
     */
    public static function provideTemplates(): iterable
    {
        yield 'single-quoted, in a block tag' => ['template' => "{% if slug matches '/^[a-z]+$/' %}ok{% endif %}\n", 'expected' => [['/^[a-z]+$/', "'/^[a-z]+$/'"]]];
        yield 'double-quoted, in an output tag' => ['template' => "<p>{{ name matches \"/^\\\\w+$/\" ? 'yes' : 'no' }}</p>\n", 'expected' => [['/^\w+$/', '"/^\\\\w+$/"']]];
        yield 'on a later line, after HTML' => ['template' => "<h1>Title</h1>\n<ul>\n  {% for item in items %}{% if item.code matches '/\\\\d{3}/' %}x{% endif %}{% endfor %}\n", 'expected' => [['/\d{3}/', "'/\\\\d{3}/'"]]];
        yield 'a single backslash is dropped, as Twig drops it' => ['template' => "{% if x matches '/\\d+/' %}{% endif %}", 'expected' => [['/d+/', "'/\\d+/'"]]];
        yield 'a quote escaped in a single-quoted string' => ['template' => "{% if x matches '/it\\'s/' %}{% endif %}", 'expected' => [["/it's/", "'/it\\'s/'"]]];
        yield 'a quote escaped in a double-quoted string' => ['template' => '{% if x matches "/say \\"hi\\"/" %}{% endif %}', 'expected' => [['/say "hi"/', '"/say \\"hi\\"/"']]];
        yield 'two on one line' => ['template' => "{% if a matches '/a/' and b matches '/b/i' %}{% endif %}", 'expected' => [['/a/', "'/a/'"], ['/b/i', "'/b/i'"]]];
        yield 'the closing braces of a tag inside the string' => ['template' => "{{ x matches '/}}/' }}", 'expected' => [['/}}/', "'/}}/'"]]];
        yield 'whitespace control dashes and tildes' => ['template' => "{%- if x matches '/a/' -%}{%~ endif ~%}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a filter around the subject' => ['template' => "{% if x|trim matches '/a/' %}{% endif %}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a parenthesised test' => ['template' => "{% if not (x matches '/a/') %}{% endif %}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'CRLF line ends count as lines' => ['template' => "<p>\r\n</p>\r\n{% if x matches '/a/' %}{% endif %}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a byte order mark is a column' => ['template' => "\xEF\xBB\xBF{% if x matches '/a/' %}{% endif %}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'an empty pattern is read too' => ['template' => "{% if x matches '' %}{% endif %}", 'expected' => [['', "''"]]];

        yield 'a variable operand is not a literal' => ['template' => '{% if x matches pattern %}{% endif %}', 'expected' => []];
        yield 'a concatenation is not a literal' => ['template' => "{% if x matches '/' ~ sep ~ '/' %}{% endif %}", 'expected' => []];
        yield 'an interpolated string is not a literal' => ['template' => '{% if x matches "/#{sep}/" %}{% endif %}', 'expected' => []];
        yield 'a comment is not read' => ['template' => "{# {% if x matches '/a/' %} #}", 'expected' => []];
        yield 'raw text is not read' => ['template' => "<p>x matches '/a/'</p>", 'expected' => []];
        yield 'a verbatim block is raw text' => ['template' => "{% verbatim %}{% if x matches '/a/' %}{% endverbatim %}{% if y matches '/b/' %}{% endif %}", 'expected' => [['/b/', "'/b/'"]]];
        yield 'the word inside a string is not the operator' => ['template' => "{{ 'x matches ' ~ '/a/' }}", 'expected' => []];
        yield 'a variable named matches is not the operator' => ['template' => '{{ matches }}{{ matches|length }}', 'expected' => []];
        yield 'a name ending in the word is not the operator' => ['template' => "{{ nomatches '/a/' }}", 'expected' => []];
        yield 'an unterminated string swallows the rest of the template, as it does for Twig' => ['template' => "{% if x matches '/a/ %}{% endif %}\n{% if y matches \"/b/\" %}{% endif %}", 'expected' => []];
        yield 'a brace of the text is not a tag' => ['template' => "{ \"json\": 1 } {{% if x matches '/a/' %}{% endif %}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a brace ending the template is not a tag' => ['template' => "{% if x matches '/a/' %}{% endif %}{", 'expected' => [['/a/', "'/a/'"]]];
        yield 'an unclosed comment swallows the rest of the template' => ['template' => "{# a note {% if x matches '/a/' %}", 'expected' => []];
        yield 'an unclosed verbatim block swallows the rest of the template' => ['template' => "{% verbatim %}{% if x matches '/a/' %}{% endif %}", 'expected' => []];
        yield 'a tag the template ends in reads nothing more' => ['template' => "{% if x matches '/a/' %}{% endif %}{% if y matches", 'expected' => [['/a/', "'/a/'"]]];
        yield 'an unterminated string elsewhere in a tag swallows the rest too' => ['template' => "{{ 'oops }}\n{% if y matches \"/b/\" %}{% endif %}", 'expected' => []];
        yield 'a comment after an output tag is a comment' => ['template' => "{{ x matches '/a/' }}{# z matches '/c/' #}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a comment right after the braces closing an output tag' => ['template' => "{{ x matches '/a/'}}{# z matches '/c/' #}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a string before the operator is passed over' => ['template' => "{% if 'a' == x and x matches '/b/' %}{% endif %}", 'expected' => [['/b/', "'/b/'"]]];
        yield 'a string glued to the operator is passed over' => ['template' => "{{ 'x'matches '/b/' }}", 'expected' => [['/b/', "'/b/'"]]];
        yield 'a tab or a newline before the operand' => ['template' => "{% if x matches\t'/a/' %}{% endif %}{% if y matches\n'/b/' %}{% endif %}", 'expected' => [['/a/', "'/a/'"], ['/b/', "'/b/'"]]];
        yield 'a concatenation after a tab, a newline or nothing' => ['template' => "{% if x matches '/'\t~ a %}{% endif %}{% if y matches '/'\n~ b %}{% endif %}{% if z matches '/'~ c %}{% endif %}", 'expected' => []];
        yield 'a template starting with a newline' => ['template' => "\n{% if x matches '/a/' %}{% endif %}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a comment opened by three characters' => ['template' => "{#}{% if x matches '/a/' %}{% endif %} #}", 'expected' => []];
        yield 'an empty comment' => ['template' => "{##}{% if x matches '/a/' %}{% endif %}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'a verbatim tag without spaces' => ['template' => "{%verbatim%}{% if x matches '/a/' %}{%endverbatim%}", 'expected' => []];
        yield 'a verbatim block of many lines ending in a tag' => ['template' => '{% verbatim %}'.str_repeat("\n", 20)."{{matches'/c/'}}{% endverbatim %}{% if y matches '/b/' %}{% endif %}", 'expected' => [['/b/', "'/b/'"]]];
        yield 'the operator glued to the opening braces' => ['template' => "{%matches '/a/'%}", 'expected' => [['/a/', "'/a/'"]]];
        yield 'an unterminated tag reads on to the next' => ['template' => "{% if x matches '/a/'\n{% if y matches '/b/' %}", 'expected' => [['/a/', "'/a/'"], ['/b/', "'/b/'"]]];
    }

    /**
     * The operator is a word of its own: a letter, a digit, an underscore
     * or a byte past ASCII glued before it makes another name.
     */
    #[Test]
    #[DataProvider('provideGluedNames')]
    public function test_a_name_the_operator_ends_is_not_the_operator(string $template): void
    {
        $this->assertSame([], TwigTemplatePatternSource::read($template, 'page.html.twig'));
    }

    /**
     * @return iterable<string, array{template: string}>
     */
    public static function provideGluedNames(): iterable
    {
        foreach (['a', 'z', 'A', 'B', 'Z', '_', "\x7f", "\xc3\xa9"] as $start) {
            yield 'starting with '.bin2hex($start) => ['template' => "{{ {$start}matches '/a/' }}{{ x{$start}matches '/b/' }}"];
        }

        foreach (['0', '5', '9'] as $digit) {
            yield 'continued by '.$digit => ['template' => "{{ x{$digit}matches '/b/' }}"];
        }
    }

    #[Test]
    public function test_the_pattern_is_the_one_twig_hands_to_the_engine(): void
    {
        $occurrences = TwigTemplatePatternSource::read("{% if x matches '/\\d+/' %}{% endif %}", 'page.html.twig');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/d+/', $occurrences[0]->pattern);
        $this->assertNull($occurrences[0]->displayPattern);
        $this->assertSame('twig:matches', $occurrences[0]->source);
        $this->assertSame('page.html.twig', $occurrences[0]->file);
        $this->assertSame(16, $occurrences[0]->fileOffset);
        $this->assertFalse($occurrences[0]->isIgnored);
    }

    /**
     * @param list<bool> $ignored one flag per pattern, in the order of the template
     */
    #[Test]
    #[DataProvider('provideMarkers')]
    public function test_a_twig_comment_holding_a_marker_silences_its_line_or_the_next(string $template, array $ignored): void
    {
        $this->assertSame($ignored, array_map(static fn (PatternOccurrence $occurrence): bool => $occurrence->isIgnored, self::patterns(TwigTemplatePatternSource::read($template, 'page.html.twig'))));
    }

    /**
     * The markers follow the patterns, as a PHP file's do, each with the
     * line it is written on, the line it covers and the issues it names.
     */
    #[Test]
    public function test_the_markers_of_the_template_follow_its_patterns(): void
    {
        $template = "<p>\n{#- @regex-ignore-next-line regex.lint.redos, quantifier.nested -#}\n{% if x matches '/(a+)+$/' %}{% endif %} {# @regex-ignore regex.lint.redos #}\n{# no marker here #}\n";
        $occurrences = TwigTemplatePatternSource::read($template, 'page.html.twig');

        $this->assertCount(2, $occurrences);
        $this->assertSame('/(a+)+$/', $occurrences[0]->pattern);
        $this->assertFalse($occurrences[0]->isIgnored, 'A marker naming issues silences those only.');
        $this->assertSame('', $occurrences[1]->pattern);
        $this->assertSame(1, $occurrences[1]->line);
        $this->assertSame('page.html.twig', $occurrences[1]->file);
        $this->assertSame('twig:matches', $occurrences[1]->source);
        $markers = $occurrences[1]->ignores;
        $this->assertIsArray($markers);
        $this->assertSame(
            [['@regex-ignore-next-line', 2, 3, ['regex.lint.redos', 'regex.lint.quantifier.nested'], 5], ['@regex-ignore', 3, 3, ['regex.lint.redos'], 45]],
            array_map(static fn (IgnoreMarker $marker): array => [$marker->marker, $marker->line, $marker->coveredLine, $marker->identifiers, $marker->column], $markers),
        );
        $this->assertSame('@regex-ignore', substr($template, $markers[1]->offset, 13));
    }

    #[Test]
    public function test_a_template_without_a_marker_carries_none(): void
    {
        $occurrences = TwigTemplatePatternSource::read("{# a note #}{% if x matches '/a/' %}{% endif %}", 'page.html.twig');

        $this->assertCount(1, $occurrences);
        $this->assertNull($occurrences[0]->ignores);
    }

    /**
     * @return iterable<string, array{template: string, ignored: list<bool>}>
     */
    public static function provideMarkers(): iterable
    {
        yield 'on the same line, after' => ['template' => "{% if x matches '/(a+)+$/' %}{% endif %} {# @regex-ignore #}\n{% if y matches '/b/' %}{% endif %}", 'ignored' => [true, false]];
        yield 'on the same line, before' => ['template' => "{# @regex-ignore #}{% if x matches '/(a+)+$/' %}{% endif %}", 'ignored' => [true]];
        yield 'with a note' => ['template' => "{# @regex-ignore the backtracking is bounded by the input #}{% if x matches '/(a+)+$/' %}{% endif %}", 'ignored' => [true]];
        yield 'next line' => ['template' => "{# @regex-ignore-next-line #}\n{% if x matches '/(a+)+$/' %}{% endif %}\n{% if y matches '/b/' %}{% endif %}", 'ignored' => [true, false]];
        yield 'next line, with whitespace control' => ['template' => "{#- @regex-ignore-next-line -#}\n{% if x matches '/a/' %}{% endif %}", 'ignored' => [true]];
        yield 'a marker on the line before, without next-line, silences nothing' => ['template' => "{# @regex-ignore #}\n{% if x matches '/a/' %}{% endif %}", 'ignored' => [false]];
        yield 'a word that only starts like the marker' => ['template' => "{# @regex-ignored #}{% if x matches '/a/' %}{% endif %}", 'ignored' => [false]];
        yield 'the marker in the middle of a sentence is prose' => ['template' => "{# see @regex-ignore #}{% if x matches '/a/' %}{% endif %}", 'ignored' => [false]];
        yield 'with tilde whitespace control' => ['template' => "{#~ @regex-ignore ~#}{% if x matches '/a/' %}{% endif %}", 'ignored' => [true]];
        yield 'glued to the braces' => ['template' => "{#@regex-ignore#}{% if x matches '/a/' %}{% endif %}", 'ignored' => [true]];
        yield 'next line, glued to the closing braces' => ['template' => "{# @regex-ignore-next-line#}\n{% if x matches '/a/' %}{% endif %}", 'ignored' => [true]];
        yield 'a marker naming an issue silences that issue only, not the line' => ['template' => "{# @regex-ignore regex.lint.redos #}{% if x matches '/a/' %}{% endif %}", 'ignored' => [false]];
    }

    #[Test]
    public function test_the_templates_under_the_linted_paths_are_read_and_the_excluded_ones_left_out(): void
    {
        $template = "{% if x matches '/a/' %}{% endif %}\n";
        $project = $this->makeProject([
            'templates/page.html.twig' => $template,
            'templates/mail/plain.twig' => $template,
            'templates/legacy/old.twig.php' => $template,
            'templates/cache/compiled.twig' => $template,
            'src/Controller.php' => "<?php preg_match('/x/', '');",
            'vendor/acme/bundle/templates/widget.html.twig' => $template,
        ]);
        $source = new TwigTemplatePatternSource();
        $files = static fn (PatternSourceContext $context): array => array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->file, $source->extract($context));

        $this->assertSame([
            $project.'/templates/mail/plain.twig',
            $project.'/templates/page.html.twig',
        ], $files(new PatternSourceContext([$project.'/templates', $project.'/src'], ['cache'], excludeBase: $project.'/templates')));
        $this->assertSame([
            $project.'/templates/cache/compiled.twig',
            $project.'/templates/mail/plain.twig',
            $project.'/templates/page.html.twig',
            $project.'/vendor/acme/bundle/templates/widget.html.twig',
        ], $files(new PatternSourceContext([$project], ['src'])));
        $this->assertSame([$project.'/templates/page.html.twig'], $files(new PatternSourceContext([$project.'/templates/page.html.twig', $project.'/src/Controller.php'], [])));
        $this->assertSame([], $files(new PatternSourceContext([$project.'/nowhere', ''], [])));
        // Each kind of path is read and the next one too: an empty one, a missing one, a file, a directory.
        $this->assertSame([
            $project.'/templates/mail/plain.twig',
            $project.'/templates/page.html.twig',
        ], $files(new PatternSourceContext(['', $project.'/nowhere', $project.'/templates/page.html.twig', $project.'/templates/mail'], [])));
    }

    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')] // chmod 0o000 keeps a file readable on Windows
    public function test_a_template_the_source_cannot_read_is_reported_unread(): void
    {
        $project = $this->makeProject(['templates/page.html.twig' => "{% if x matches '/a/' %}{% endif %}\n"]);
        $this->assertTrue(chmod($project.'/templates/page.html.twig', 0o000));

        try {
            $occurrences = (new TwigTemplatePatternSource())->extract(new PatternSourceContext([$project.'/templates'], []));
        } finally {
            chmod($project.'/templates/page.html.twig', 0o644);
        }

        $this->assertCount(1, $occurrences);
        $this->assertSame('', $occurrences[0]->pattern);
        $this->assertSame($project.'/templates/page.html.twig', $occurrences[0]->file);
        $this->assertSame('twig:matches', $occurrences[0]->source);
        $this->assertNotNull($occurrences[0]->unread);
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<array{string, int, int}>
     */
    private static function read(array $occurrences): array
    {
        return array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->pattern, $occurrence->line, (int) $occurrence->column], self::patterns($occurrences));
    }

    /**
     * The occurrences holding a pattern: the one carrying the markers,
     * when the template holds any, left out.
     *
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<PatternOccurrence>
     */
    private static function patterns(array $occurrences): array
    {
        return array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null === $occurrence->ignores));
    }
}
