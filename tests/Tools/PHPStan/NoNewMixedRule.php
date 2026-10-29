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

namespace PHPRegex\Tests\Tools\PHPStan;

use PhpParser\Node;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Holds the mixed types declared under the given paths to a baseline: a
 * declaration may write as many as its baseline entry allows, the entry
 * giving the reason the type stays mixed, and no more. A declaration that
 * writes fewer, or an entry that names nothing left, is reported too, so
 * that the baseline only goes down.
 *
 * The baseline is a PHP file returning, by file path relative to it, the
 * entries of each symbol: [reason, count].
 *
 * @internal the library's check on its own declared types
 *
 * @implements Rule<CollectedDataNode>
 */
final class NoNewMixedRule implements Rule
{
    public const IDENTIFIER = 'phpRegex.noNewMixed';

    /**
     * Why a declared type stays mixed.
     */
    public const REASONS = [
        // A value json_decode() or unserialize() gives, before it is read.
        'json-decode',
        // A signature a host interface fixes.
        'host-contract',
        // A value a host API returns untyped, checked where it is read.
        'host-return',
        // A closure whose result the caller gets back as it is.
        'template-identity',
        // The input of a method whose runtime checks are its contract.
        'validator-input',
        // A JSON-RPC or LSP slot, any JSON value by the protocol.
        'protocol-slot',
        // The result of a user callback, read without being looked into.
        'callback-opaque',
        // A value handed whole to serialize(), json_encode() or reflection.
        'reflection-sink',
        // An options array whose keys the caller chooses.
        'open-options-bag',
        // The by-reference argument of a preg_*() wrapper.
        'preg-mirror',
    ];

    /**
     * @param string|null  $baseline the PHP file of the baseline; none allows no mixed type
     * @param list<string> $paths    the directories whose files are judged
     *
     * @throws InvalidRegexOptionException when the baseline names no file
     */
    public function __construct(private readonly ?string $baseline, private readonly array $paths)
    {
        if (null !== $baseline && !is_file($baseline)) {
            throw new InvalidRegexOptionException(\sprintf('"noNewMixed.baseline" names no file: %s.', $baseline));
        }
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        $entries = $this->entries($errors);
        $root = null === $this->baseline ? '' : str_replace('\\', '/', \dirname($this->baseline)).'/';

        foreach ($entries as $file => $symbols) {
            if (!is_file($root.$file)) {
                $errors[] = $this->error(\sprintf('The baseline of declared mixed types names %s, which does not exist: remove its entries.', $file), $this->baseline ?? $file, 1);
            }
        }

        $unseen = $entries;
        foreach ($node->get(MixedTypeCollector::class) as $file => $records) {
            // Spelled with "/" as the baseline and the paths spell it, whatever the host.
            $file = str_replace('\\', '/', $file);
            if (!$this->judges($file)) {
                continue;
            }

            $relative = str_starts_with($file, $root) ? substr($file, \strlen($root)) : $file;
            $allowed = $entries[$relative] ?? [];
            unset($unseen[$relative]);

            foreach (self::countsBySymbol($records) as $symbol => [$count, $line]) {
                [$reason, $limit] = $allowed[$symbol] ?? ['', 0];
                unset($allowed[$symbol]);

                if ($count > $limit) {
                    $errors[] = $this->error(\sprintf('%s declares %s where the baseline allows %d: narrow the type, or give the baseline the reason it stays mixed.', $symbol, self::types($count), $limit), $file, $line);
                } elseif ($count < $limit) {
                    $errors[] = $this->error(\sprintf('%s declares %s where the baseline allows %d (%s): lower the baseline.', $symbol, self::types($count), $limit, $reason), $file, $line);
                }
            }

            foreach ($allowed as $symbol => [$reason, $limit]) {
                $errors[] = $this->error(\sprintf('%s declares no mixed type where the baseline allows %d (%s): remove the entry.', $symbol, $limit, $reason), $file, 1);
            }
        }

        // A whole analysis reads every file: one that gave no record has no
        // mixed type left. A partial one says nothing of the files it skipped.
        if (!$node->isOnlyFilesAnalysis()) {
            foreach ($unseen as $file => $symbols) {
                if (!is_file($root.$file) || !$this->judges($root.$file)) {
                    continue;
                }

                foreach ($symbols as $symbol => [$reason, $limit]) {
                    $errors[] = $this->error(\sprintf('%s declares no mixed type where the baseline allows %d (%s): remove the entry.', $symbol, $limit, $reason), $root.$file, 1);
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<array{symbol: string, line: int, position: int, count: int}> $records
     *
     * @return array<string, array{int, int}> the count of each symbol, and the line of its first record
     */
    private static function countsBySymbol(array $records): array
    {
        $seen = [];
        $counts = [];
        foreach ($records as $record) {
            // A trait's declarations come once for each class using it.
            if (isset($seen[$record['position']])) {
                continue;
            }

            $seen[$record['position']] = true;
            [$count, $line] = $counts[$record['symbol']] ?? [0, $record['line']];
            $counts[$record['symbol']] = [$count + $record['count'], min($line, $record['line'])];
        }

        return $counts;
    }

    private static function types(int $count): string
    {
        return 1 === $count ? '1 mixed type' : $count.' mixed types';
    }

    private function error(string $message, string $file, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier(self::IDENTIFIER)
            ->file($file)
            ->line($line)
            ->build();
    }

    private function judges(string $file): bool
    {
        foreach ($this->paths as $path) {
            if (str_starts_with($file, rtrim($path, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entries of the baseline; one it cannot read allows nothing, and
     * says why.
     *
     * @param list<IdentifierRuleError> $errors
     *
     * @return array<string, array<string, array{string, int}>>
     */
    private function entries(array &$errors): array
    {
        if (null === $this->baseline) {
            return [];
        }

        $entries = require $this->baseline;
        if (!\is_array($entries)) {
            $errors[] = $this->error(\sprintf('The baseline of declared mixed types, %s, is no PHP file returning an array.', $this->baseline), $this->baseline, 1);

            return [];
        }

        $read = [];
        foreach ($entries as $file => $symbols) {
            foreach (\is_array($symbols) ? $symbols : [] as $symbol => $entry) {
                if (!\is_array($entry) || !\in_array($entry[0] ?? null, self::REASONS, true) || !\is_int($entry[1] ?? null) || $entry[1] < 1) {
                    $errors[] = $this->error(\sprintf('The baseline of declared mixed types gives %s in %s no [reason, count]: the reasons are %s.', $symbol, $file, implode(', ', self::REASONS)), $this->baseline, 1);

                    continue;
                }

                $read[(string) $file][(string) $symbol] = [$entry[0], $entry[1]];
            }
        }

        return $read;
    }
}
