<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Optimizer;

use RegexParser\Exception\InvalidRegexOptionException;

/**
 * What the optimizer may rewrite. As a PHP array it is keyed in snake_case,
 * as Regex::create() and the Symfony and Laravel configs are; regex.json and
 * the PHPStan parameters spell the same names in camelCase.
 */
final readonly class OptimizerOptions
{
    /**
     * The snake_case key of each option, by the camelCase one.
     */
    private const KEYS = [
        'digits' => 'digits',
        'word' => 'word',
        'ranges' => 'ranges',
        'canonicalizeCharClasses' => 'canonicalize_char_classes',
        'possessive' => 'possessive',
        'factorize' => 'factorize',
        'minQuantifierCount' => 'min_quantifier_count',
        'verifyWithAutomata' => 'verify_with_automata',
    ];

    /**
     * The 1.x key of an option renamed in 2.0, and its name now.
     */
    private const RENAMED = [
        'autoPossessify' => 'possessive',
        'allowAlternationFactorization' => 'factorize',
    ];

    /**
     * @param bool $digits                  "[0-9]" becomes "\d"
     * @param bool $word                    "[a-zA-Z0-9_]" becomes "\w"
     * @param bool $ranges                  runs of consecutive characters become ranges
     * @param bool $canonicalizeCharClasses members of a class are sorted and merged
     * @param bool $possessive              a quantifier becomes possessive where nothing after it can
     *                                      take back what it matched; off by default, as a backreference
     *                                      can make it change what the pattern matches
     * @param bool $factorize               alternatives sharing a prefix share it, "abc|abd" becoming
     *                                      "ab(?:c|d)"; off by default, as it makes an /x pattern harder
     *                                      to read
     * @param int  $minQuantifierCount      how many repetitions of an item become a quantifier, at least 2
     * @param bool $verifyWithAutomata      a rewrite is only offered when the automata prove it matches
     *                                      the same strings
     */
    public function __construct(
        public bool $digits = true,
        public bool $word = true,
        public bool $ranges = true,
        public bool $canonicalizeCharClasses = true,
        public bool $possessive = false,
        public bool $factorize = false,
        public int $minQuantifierCount = 4,
        public bool $verifyWithAutomata = false,
    ) {
        if ($minQuantifierCount < 2) {
            throw new InvalidRegexOptionException(\sprintf('Optimizer option "min_quantifier_count" must be an integer of at least 2, %d given.', $minQuantifierCount));
        }
    }

    /**
     * @param array<array-key, mixed> $options keyed in snake_case: digits, word, ranges,
     *                                         canonicalize_char_classes, possessive, factorize,
     *                                         min_quantifier_count, verify_with_automata
     *
     * @throws InvalidRegexOptionException on a key it does not know or a value of the wrong type
     */
    public static function fromArray(array $options): self
    {
        return self::read($options, array_flip(self::KEYS));
    }

    /**
     * @param array<array-key, mixed> $options keyed in camelCase, as regex.json and the PHPStan
     *                                         parameters are
     *
     * @throws InvalidRegexOptionException on a key it does not know or a value of the wrong type
     */
    public static function fromCamelCaseArray(array $options): self
    {
        return self::read($options, array_combine(array_keys(self::KEYS), array_keys(self::KEYS)));
    }

    /**
     * @param array<array-key, mixed> $options
     * @param array<string, string>   $parameters the constructor parameter of each accepted key
     */
    private static function read(array $options, array $parameters): self
    {
        $flags = [];
        $minQuantifierCount = null;
        foreach ($options as $key => $value) {
            $parameter = $parameters[$key] ?? null;
            if (null === $parameter) {
                $renamed = self::RENAMED[$key] ?? null;

                throw new InvalidRegexOptionException(null !== $renamed
                    ? \sprintf('Unknown optimizer option "%s": it is "%s" since 2.0.', $key, $renamed)
                    : \sprintf('Unknown optimizer option "%s"; the options are: %s.', $key, implode(', ', array_keys($parameters))));
            }

            if ('minQuantifierCount' === $parameter) {
                if (!\is_int($value) || $value < 2) {
                    throw new InvalidRegexOptionException(\sprintf('Optimizer option "%s" must be an integer of at least 2, %s given.', $key, get_debug_type($value)));
                }
                $minQuantifierCount = $value;

                continue;
            }

            if (!\is_bool($value)) {
                throw new InvalidRegexOptionException(\sprintf('Optimizer option "%s" must be a bool, %s given.', $key, get_debug_type($value)));
            }
            $flags[$parameter] = $value;
        }

        $defaults = new self();

        return new self(
            $flags['digits'] ?? $defaults->digits,
            $flags['word'] ?? $defaults->word,
            $flags['ranges'] ?? $defaults->ranges,
            $flags['canonicalizeCharClasses'] ?? $defaults->canonicalizeCharClasses,
            $flags['possessive'] ?? $defaults->possessive,
            $flags['factorize'] ?? $defaults->factorize,
            $minQuantifierCount ?? $defaults->minQuantifierCount,
            $flags['verifyWithAutomata'] ?? $defaults->verifyWithAutomata,
        );
    }
}
