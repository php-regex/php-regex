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

namespace PHPRegex\Mcp;

use PHPRegex\Mcp\Exception\UnknownToolException;
use PHPRegex\Mcp\Tool\ExplainTool;
use PHPRegex\Mcp\Tool\FormatTool;
use PHPRegex\Mcp\Tool\RedosTool;
use PHPRegex\Mcp\Tool\TranspileTool;
use PHPRegex\Mcp\Tool\ValidateTool;

/**
 * The tools the server serves, with the schemas a client reads. The
 * schemas are written here by hand, never derived from a signature: a
 * docblock that moves never changes what a client was promised.
 *
 * @phpstan-import-type Target from RegexFactory
 */
final class ToolCatalog
{
    /**
     * @param array<string, ToolDefinition> $tools by name, in the order a client lists them
     */
    private function __construct(private readonly array $tools) {}

    /**
     * The first catalog: validate, explain, format, transpile and the ReDoS
     * verdict, each judged for the target given unless a call names its own.
     *
     * @param Target $target
     */
    public static function v1(array $target = []): self
    {
        $regexes = new RegexFactory($target);
        $catalog = new self([]);

        return $catalog
            ->with(new ToolDefinition(
                'regex_validate',
                'Validate a regex pattern',
                'Whether the target PHP compiles a PCRE pattern written as in PHP source (/…/flags, delimiters included): the verdict, the error with its byte offset, and a hint. An invalid pattern is the answer, not a failure.',
                self::input(),
                self::output(['pattern' => ['type' => 'string'], 'validation' => self::validation()], ['pattern', 'validation']),
                new ValidateTool($regexes),
            ))
            ->with(new ToolDefinition(
                'regex_explain',
                'Explain a regex pattern',
                'A PCRE pattern written as in PHP source (/…/flags) explained in plain language, construct by construct, as the target PHP reads it. A pattern the target refuses is reported with its validation instead.',
                self::input(),
                self::output(['pattern' => ['type' => 'string'], 'explanation' => ['type' => 'string']], ['pattern', 'explanation']),
                new ExplainTool($regexes),
            ))
            ->with(new ToolDefinition(
                'regex_format',
                'Lay a regex pattern out, or compact it',
                'A PCRE pattern written as in PHP source (/…/flags) laid out over lines under the x flag at the width given, or compacted to one line; the result matches exactly what the pattern given matches. A pattern that cannot be laid out safely is given back as written, with the reason.',
                self::input([
                    'width' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The width a line aims at, indent included. Default 80.'],
                    'compact' => ['type' => 'boolean', 'description' => 'Write the pattern on one line, without x, layout or comments. Default false.'],
                ]),
                self::output([
                    'pattern' => ['type' => 'string'],
                    'formatted' => ['type' => 'string'],
                    'changed' => ['type' => 'boolean'],
                    'refusal' => ['type' => ['string', 'null']],
                ], ['pattern', 'formatted', 'changed', 'refusal']),
                new FormatTool($regexes),
            ))
            ->with(new ToolDefinition(
                'regex_transpile',
                'Translate a regex pattern to JavaScript, HTML or Python',
                'A PCRE pattern written as in PHP source (/…/flags) rewritten for JavaScript (js), an HTML pattern attribute (html) or Python (python), with every note on what was rewritten and every warning on what matches differently. A construct the dialect cannot express is refused with its reason, never approximated.',
                self::input([
                    'target' => ['type' => 'string', 'enum' => TranspileTool::TARGETS, 'description' => 'The dialect: js, html or python.'],
                    'python_version' => ['type' => 'string', 'description' => 'The Python release the pattern runs on, as "3.11", which carries atomic groups.'],
                ], ['pattern', 'target']),
                self::output([
                    'target' => ['type' => 'string'],
                    'source' => ['type' => 'string'],
                    'pattern' => ['type' => 'string'],
                    'flags' => ['type' => 'string'],
                    'literal' => ['type' => 'string'],
                    'constructor' => ['type' => 'string'],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], ['target', 'source', 'pattern', 'flags', 'literal', 'constructor', 'warnings', 'notes']),
                new TranspileTool($regexes),
            ))
            ->with(new ToolDefinition(
                'regex_redos',
                'Judge a regex pattern for ReDoS',
                'The ReDoS verdict on a PCRE pattern written as in PHP source (/…/flags): severity, complexity, the witness input that pumps the backtracking, the hotspots in the pattern and the recommendations. In confirmed mode the pattern is run on this machine against crafted inputs, under the library\'s own caps, to prove the verdict.',
                self::input([
                    'mode' => ['type' => 'string', 'enum' => ['theoretical', 'confirmed'], 'description' => 'theoretical (default) reads the pattern; confirmed also replays the witness through the engine on this machine, bounded by the library\'s caps.'],
                    'threshold' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical'], 'description' => 'The lowest severity confirmed mode replays.'],
                ]),
                self::output(['pattern' => ['type' => 'string'], 'redos' => ['type' => 'object']], ['pattern', 'redos']),
                new RedosTool($regexes),
            ));
    }

    /**
     * @return list<ToolDefinition>
     */
    public function tools(): array
    {
        return array_values($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @throws UnknownToolException
     */
    public function get(string $name): ToolDefinition
    {
        return $this->tools[$name] ?? throw UnknownToolException::named($name);
    }

    /**
     * This catalog with one more tool, or one replaced by name.
     */
    public function with(ToolDefinition $tool): self
    {
        return new self([...$this->tools, $tool->name => $tool]);
    }

    /**
     * The arguments every tool takes, plus the tool's own.
     *
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private static function input(array $properties = [], array $required = ['pattern']): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'The pattern as written in PHP source, delimiters and flags included, as /^\d+$/i. The delimiters are required.'],
                ...$properties,
                'php_version' => ['type' => 'string', 'description' => 'The PHP release to judge for, as "8.2". Default: the server\'s target, else the running PHP.'],
                'pcre_version' => ['type' => 'string', 'description' => 'The PCRE2 release to judge for, as "10.44". Default: the one the PHP release bundles.'],
            ],
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * The structured answer: the success document, or the CLI's error
     * envelope, which carries the pattern's validation when the pattern is
     * what failed.
     *
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private static function output(array $properties, array $required): array
    {
        return [
            'oneOf' => [
                ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
                [
                    'type' => 'object',
                    'properties' => [
                        'error' => ['type' => 'string'],
                        'stage' => ['type' => 'string'],
                        'validation' => self::validation(),
                    ],
                    'required' => ['error', 'stage'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * The shared validation object of the CLI's documents.
     *
     * @return array<string, mixed>
     */
    private static function validation(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_valid' => ['type' => 'boolean'],
                'error' => ['type' => ['string', 'null']],
                'complexity_score' => ['type' => 'integer'],
                'category' => ['type' => ['string', 'null']],
                'offset' => ['type' => ['integer', 'null']],
                'caret_snippet' => ['type' => ['string', 'null']],
                'hint' => ['type' => ['string', 'null']],
                'error_code' => ['type' => ['string', 'null']],
            ],
            'required' => ['is_valid', 'error', 'complexity_score', 'category', 'offset', 'caret_snippet', 'hint', 'error_code'],
            'additionalProperties' => false,
        ];
    }
}
