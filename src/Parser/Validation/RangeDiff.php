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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\RegexParser;

/**
 * Whether a pattern changes between two PHP versions: the first target of
 * the range, then the later ones, each judging the pattern on its own.
 *
 *     $verdict = RangeDiff::between(80200, 80510)->diff('/a{,3}/');
 *     $verdict->readsOtherwise; // PHP 8.4 with PCRE2 10.44: "{,3}" repeats there
 *
 * A later target can refuse a pattern the first accepts, as PHP 8.5 refuses
 * "\K" in a lookaround, or accept it and read it into another tree, as
 * PCRE2 10.43 reads "a{,3}" as a quantifier where 10.42 read the text. The
 * trees are compared, not the matches: a change of meaning that keeps the
 * tree, such as "{1}+" between PCRE2 10.33 and 10.34, is not seen.
 *
 * A release older than the library knows in full is judged with the rules
 * of the oldest it does, and the verdict says so (judgedWith, notice()).
 */
final class RangeDiff
{
    /**
     * @param RegexParser       $from  the parser of the first target of the range
     * @param list<RegexParser> $later the parsers of the later targets, in ascending order
     */
    public function __construct(private readonly RegexParser $from, private readonly array $later = []) {}

    /**
     * From one PHP version to another, each with the PCRE2 its sources
     * bundle: the first, then every PHP version a verdict may change at
     * above it, up to the last (PcreTarget::phpVersionBoundaries()). Each
     * target is the base parser for it (RegexParser::withTarget()): the
     * locale, recursion depth, limits and cache of the base hold at every
     * point, and only a target that is the running engine compiles with the
     * running PHP, when the base does.
     *
     * @param int              $from a PHP_VERSION_ID, 70400 for PHP 7.4
     * @param int              $to   a PHP_VERSION_ID at or after $from
     * @param RegexParser|null $base the parser whose options every target keeps; null reads the
     *                               defaults, under the runtime's locale
     *
     * @throws InvalidRegexOptionException when the range ends before it starts, or starts
     *                                     below 70000, PHP 7.0's PHP_VERSION_ID, which
     *                                     "php_version" refuses
     */
    public static function between(int $from, int $to, ?RegexParser $base = null): self
    {
        if ($to < $from) {
            throw new InvalidRegexOptionException(\sprintf(
                'A range of PHP versions ends at or after its start, not at %s from %s.',
                self::phpLabel($to),
                self::phpLabel($from),
            ));
        }

        $base ??= RegexParser::create();
        $locale = $base->target()->locale;
        $first = $base->withTarget(ParserOptions::fromArray(['php_version' => $from, 'pcre_version' => PcreTarget::bundledWith($from)->pcreVersion, 'locale' => $locale])->target);
        $later = [];
        foreach (PcreTarget::phpVersionBoundaries() as $point) {
            if ($point > $from && $point <= $to) {
                $later[] = $base->withTarget(new PcreTarget($point, PcreTarget::bundledWith($point)->pcreVersion, $locale));
            }
        }

        return new self($first, $later);
    }

    /**
     * The parser of the first target.
     */
    public function from(): RegexParser
    {
        return $this->from;
    }

    /**
     * The parser of the last target: the first one in a range of one.
     */
    public function to(): RegexParser
    {
        return [] === $this->later ? $this->from : $this->later[\count($this->later) - 1];
    }

    /**
     * The targets of the range, the first one first.
     *
     * @return list<PcreTarget>
     */
    public function targets(): array
    {
        return [$this->from->target(), ...array_map(static fn (RegexParser $parser): PcreTarget => $parser->target(), $this->later)];
    }

    /**
     * How each target of the range judges the pattern. A pattern the first
     * target refuses is judged no further; one every target accepts has its
     * trees compared, unless $compareTrees is false.
     */
    public function diff(string $pattern, bool $compareTrees = true): RangeVerdict
    {
        $judgedWith = self::judgedWith($this->targets());
        $from = new TargetVerdict($this->from->target(), $this->from->validate($pattern));
        if (!$from->validation->isValid) {
            return new RangeVerdict($from, [], null, $judgedWith);
        }

        $later = [];
        $refused = false;
        foreach ($this->later as $parser) {
            $later[] = $verdict = new TargetVerdict($parser->target(), $parser->validate($pattern));
            $refused = $refused || !$verdict->validation->isValid;
        }

        $readsOtherwise = null;
        if ($compareTrees && !$refused && [] !== $this->later) {
            $tree = $this->from->parse($pattern)->accept(new NodeDumper());
            foreach ($this->later as $parser) {
                if ($parser->parse($pattern)->accept(new NodeDumper()) !== $tree) {
                    $readsOtherwise = $parser->target();

                    break;
                }
            }
        }

        return new RangeVerdict($from, $later, $readsOtherwise, $judgedWith);
    }

    /**
     * The releases of the targets older than the library knows in full,
     * each with the release whose rules judge it, in the order met.
     *
     * @param list<PcreTarget> $targets
     *
     * @return array<string, string> release => the release whose rules judge it, "10.33" => "10.39"
     */
    public static function judgedWith(array $targets): array
    {
        $judgedWith = [];
        foreach ($targets as $target) {
            if ($target->judgedWith() !== $target->pcreVersion) {
                $judgedWith[$target->pcreVersion] = $target->judgedWith();
            }
        }

        return $judgedWith;
    }

    /**
     * What judgedWith() says, for people: "PCRE2 10.33 and 10.35 are judged
     * with the PCRE2 10.39 rules, the oldest this library knows in full."
     * Null when every release is judged with its own rules.
     *
     * @param array<string, string> $judgedWith as judgedWith() gives it
     */
    public static function notice(array $judgedWith): ?string
    {
        if ([] === $judgedWith) {
            return null;
        }

        $releases = array_map(strval(...), array_keys($judgedWith));
        $last = (string) array_pop($releases);

        return \sprintf(
            'PCRE2 %s %s judged with the PCRE2 %s rules, the oldest this library knows in full.',
            [] === $releases ? $last : implode(', ', $releases).' and '.$last,
            [] === $releases ? 'is' : 'are',
            $judgedWith[$last],
        );
    }

    private static function phpLabel(int $phpVersionId): string
    {
        $label = \sprintf('%d.%d', intdiv($phpVersionId, 10000), intdiv($phpVersionId, 100) % 100);

        return 0 === $phpVersionId % 100 ? $label : $label.'.'.($phpVersionId % 100);
    }
}
