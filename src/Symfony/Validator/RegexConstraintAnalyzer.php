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

namespace PHPRegex\Symfony\Validator;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Target\JavaScript\VModeCompatibility;
use PHPRegex\Transpiler\Transpiler;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\GetterMetadata;

/**
 * Audits the Regex constraints of validated classes: what the browser
 * checks against what the server checks, and what the other constraints of
 * the same property make of the pattern.
 *
 * A form renders a Regex constraint as the HTML pattern attribute its
 * getHtmlPattern() gives, and the browser compiles it as ^(?:H)$ under the
 * v flag. For each Regex constraint the audit asks:
 *
 * - whether that attribute is dropped, as getHtmlPattern() gives none for a
 *   pattern with flags or a pair of delimiters, and which htmlPattern would
 *   carry it;
 * - whether a browser compiles it at all, and reads it as PCRE does
 *   (VModeCompatibility);
 * - whether it accepts the values the server accepts, and only those: the
 *   pattern under preg_match() against ^(?:H)$ read as the browser reads
 *   it, code points, \w \d \b as ASCII, \s and . as JavaScript reads
 *   them, over the values the browser checks (not empty, no line break);
 *
 * and across the constraints of a property, whether a Length or a Choice
 * leaves any value the Regex accepts, and whether two Regex constraints of
 * a group can both pass.
 *
 * Findings are errors (a value the browser accepts and the server rejects,
 * a pattern the browser refuses or misreads, a property no value can
 * satisfy),
 * warnings (a value the server accepts and the browser rejects, a dropped
 * attribute) or notices (a question the solver could not answer).
 *
 * @internal
 *
 * @phpstan-type ValidatorFinding array{
 *     class: string,
 *     property: string,
 *     pattern: string,
 *     check: string,
 *     severity: 'error'|'warning'|'notice',
 *     message: string,
 *     htmlPattern?: string,
 *     example?: string,
 *     suggestion?: string,
 * }
 */
final class RegexConstraintAnalyzer
{
    /**
     * The delimiters the HTML pattern is read between to check its syntax,
     * the first one it does not write.
     */
    private const DELIMITERS = ['/', '#', '~', '%', '@', '!', ',', ';', '`', '='];

    /**
     * The ASCII word characters, as JavaScript reads \w even under v.
     */
    private const WORD = 'A-Za-z0-9_';

    /**
     * JavaScript's white space but the line breaks no input holds: \s
     * reads WhiteSpace and LineTerminator, Unicode's Zs among them.
     */
    private const SPACE = '\t\x{B}\f \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private readonly RegexParser $parser;

    private readonly Transpiler $transpiler;

    private readonly PcreEngine $engine;

    private readonly SolverOptions $options;

    /**
     * @var array<string, array{?string, ?string, bool}|string> each comparison made, or why it failed, by pattern and HTML pattern
     */
    private array $compared = [];

    /**
     * @var array<string, array{?string, bool}> each suggestion made, by pattern
     */
    private array $suggested = [];

    public function __construct(
        private readonly LanguageSolver $solver = new LanguageSolver(),
        ?RegexParser $parser = null,
        ?SolverOptions $options = null,
    ) {
        $this->parser = $parser ?? RegexParser::create();
        $this->transpiler = new Transpiler($this->parser);
        $this->engine = new PcreEngine();
        $this->options = $options ?? new SolverOptions(MatchMode::Partial);
    }

    /**
     * @param iterable<ClassMetadataInterface> $classes
     */
    public function analyze(iterable $classes): RegexConstraintReport
    {
        $findings = [];
        $stats = ['classes' => 0, 'properties' => 0, 'regex' => 0, 'html_compared' => 0];

        foreach ($classes as $metadata) {
            $stats['classes']++;
            $class = $metadata->getClassName();
            foreach ($metadata->getConstrainedProperties() as $property) {
                foreach ($metadata->getPropertyMetadata($property) as $member) {
                    $name = $member instanceof GetterMetadata ? $member->getName().'()' : '$'.$member->getPropertyName();
                    $constraints = [];
                    foreach ($member->getConstraints() as $constraint) {
                        $constraints = [...$constraints, ...self::flatten($constraint)];
                    }

                    $regexes = [];
                    foreach ($constraints as $constraint) {
                        if ($constraint instanceof Regex && \is_string($constraint->pattern) && '' !== $constraint->pattern) {
                            $regexes[] = $constraint;
                        }
                    }

                    if ([] === $regexes) {
                        continue;
                    }

                    $stats['properties']++;
                    $subject = ['class' => $class, 'property' => $name];
                    foreach ($regexes as $regex) {
                        $stats['regex']++;
                        foreach ($this->checkHtmlPattern($regex, $subject, $stats) as $finding) {
                            $findings[] = $finding;
                        }

                        foreach ($constraints as $other) {
                            $found = match (true) {
                                $other instanceof Length => $this->checkLength($regex, $other, $subject, $constraints),
                                $other instanceof Choice => $this->checkChoice($regex, $other, $subject),
                                default => [],
                            };
                            foreach ($found as $finding) {
                                $findings[] = $finding;
                            }
                        }
                    }

                    foreach ($this->checkConsistency($regexes, $subject, $constraints) as $finding) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return new RegexConstraintReport($findings, $stats);
    }

    /**
     * The HTML pattern a browser reads for the constraint, as PCRE: the
     * value of the attribute, ^(?:H)$, under u (the browser reads code
     * points), with \w \d \b \W \D \B and . read as ASCII JavaScript reads
     * them, over the values an input holds: none holds a line break. A "/"
     * the value writes is escaped.
     */
    public static function browserPattern(string $html): string
    {
        return '/^(?:'.self::asJavaScriptReadsIt($html).')$/Du';
    }

    /**
     * @param array{class: string, property: string} $subject
     * @param array<string, int>                     $stats
     *
     * @return list<ValidatorFinding>
     */
    private function checkHtmlPattern(Regex $regex, array $subject, array &$stats): array
    {
        $pattern = (string) $regex->pattern;
        $html = $regex->getHtmlPattern();

        if (null === $html) {
            if (null !== $regex->htmlPattern) {
                // An empty htmlPattern turns the attribute off on purpose.
                return [];
            }

            $flags = $this->flagsOf($pattern);
            $message = '' !== $flags
                ? \sprintf('The pattern has flags ("%s"), so getHtmlPattern() gives no HTML pattern: the browser checks nothing.', $flags)
                : 'getHtmlPattern() gives no HTML pattern, as the pattern does not end with the character it starts with: the browser checks nothing.';
            [$suggestion, $verified] = $regex->match ? $this->suggest($pattern) : [null, false];
            if (null === $suggestion) {
                return [self::finding($subject, $pattern, 'html_pattern.dropped', 'warning', $message)];
            }

            $message .= match (true) {
                null !== $regex->normalizer => ' The html-pattern target writes this htmlPattern; the server normalizes the value first, which the audit does not model.',
                $verified => ' This htmlPattern matches the same values.',
                default => ' The html-pattern target writes this htmlPattern; the solver could not prove it matches the same values.',
            };

            return [self::finding($subject, $pattern, 'html_pattern.dropped', 'warning', $message, ['suggestion' => $suggestion])];
        }

        $findings = [];
        $misread = $this->vModeFindings($html, $this->parses($pattern));
        if (null === $misread) {
            return [self::finding($subject, $pattern, 'html_pattern.unchecked', 'notice', \sprintf('The HTML pattern writes every delimiter PCRE could read it between (%s): how a browser reads it was not checked.', implode('', self::DELIMITERS)), ['htmlPattern' => $html])];
        }

        // An explicit htmlPattern is written for the browser: what a browser
        // reads otherwise than PCRE is no error of its own, the comparison
        // reads it as the browser does (\v), or says it cannot.
        $refused = array_values(array_filter($misread, static fn (array $finding): bool => $finding['refused']));
        if (null !== $regex->htmlPattern && [] === $refused) {
            $unread = array_values(array_filter($misread, static fn (array $finding): bool => 'char_type' !== $finding['construct']));
            if ([] !== $unread) {
                return [self::finding($subject, $pattern, 'html_pattern.unchecked', 'notice', 'A browser reads the HTML pattern under v otherwise than PCRE, which the comparison does not model: '.self::describe($unread), ['htmlPattern' => $html])];
            }

            $misread = [];
        }

        if ([] !== $misread) {
            $message = ([] !== $refused
                ? 'A browser refuses to compile the HTML pattern under v, and then checks nothing: '
                : 'A browser reads the HTML pattern under v otherwise than PCRE: ').self::describe([] !== $refused ? $refused : $misread);
            [$suggestion] = $regex->match ? $this->suggest($pattern) : [null];
            $extra = ['htmlPattern' => $html];
            if (null !== $suggestion && $suggestion !== $html) {
                $extra['suggestion'] = $suggestion;
            }

            return [self::finding($subject, $pattern, [] !== $refused ? 'html_pattern.invalid' : 'html_pattern.misread', 'error', $message, $extra)];
        }

        if (null !== $regex->normalizer) {
            return [self::finding($subject, $pattern, 'html_pattern.unchecked', 'notice', 'The server normalizes the value before it matches the pattern, which the audit does not model: the HTML pattern was not compared with the pattern.', ['htmlPattern' => $html])];
        }

        $browser = self::browserPattern($html);
        $stats['html_compared']++;

        // A class often repeats a pattern: each pair is compared once.
        $key = ($regex->match ? '1' : '0').$pattern."\0".$browser;
        if (!isset($this->compared[$key])) {
            try {
                $this->compared[$key] = $regex->match
                    ? $this->compareMatching($pattern, $browser)
                    : $this->compareNotMatching($pattern, $browser);
            } catch (ComplexityException|RegexException|\InvalidArgumentException $e) {
                $this->compared[$key] = $e->getMessage();
            }
        }

        if (\is_string($this->compared[$key])) {
            return [self::finding($subject, $pattern, 'html_pattern.unchecked', 'notice', 'The HTML pattern could not be compared with the pattern: '.$this->compared[$key], ['htmlPattern' => $html])];
        }

        [$looser, $stricter, $searched] = $this->compared[$key];

        if (null !== $looser) {
            $extra = ['htmlPattern' => $html, 'example' => $looser];
            [$suggestion, $verified] = $regex->match ? $this->suggest($pattern) : [null, false];
            if ($verified && null !== $suggestion && $suggestion !== $html) {
                $extra['suggestion'] = $suggestion;
            }

            $findings[] = self::finding($subject, $pattern, 'html_pattern.looser', 'error', \sprintf('The browser accepts %s, which the server rejects.', self::quote($looser)), $extra);
        }

        if (null !== $stricter) {
            $findings[] = self::finding($subject, $pattern, 'html_pattern.stricter', 'warning', \sprintf('The browser rejects %s, which the server accepts.', self::quote($stricter)), ['htmlPattern' => $html, 'example' => $stricter]);
        }

        if (!$searched) {
            $findings[] = self::finding($subject, $pattern, 'html_pattern.unchecked', 'notice', 'The pattern reads bytes and the browser code points: no value the server accepts and the browser rejects was searched for.', ['htmlPattern' => $html]);
        }

        return $findings;
    }

    /**
     * For a pattern the value must match: a value the browser accepts and
     * the pattern does not match, and one the pattern matches and the
     * browser rejects, each the shortest of the values the browser checks:
     * neither the empty value, which Regex does not check either, nor one
     * holding a line break, which no input holds. The third item says
     * whether the second was searched for.
     *
     * @throws ComplexityException|RegexException|\InvalidArgumentException
     *
     * @return array{?string, ?string, bool}
     */
    private function compareMatching(string $pattern, string $browser): array
    {
        $looser = $this->solver->subsetOf(self::checkedAndAccepted($browser), $pattern, $this->options);
        $stricter = $this->solver->subsetOf($pattern, self::acceptedOrUnchecked($browser), $this->options);

        return [$looser->isSubset ? null : $looser->counterExample, $stricter->isSubset ? null : $stricter->counterExample, true];
    }

    /**
     * For a pattern the value must not match: a value the browser checks
     * and accepts that the pattern matches; then one the browser checks,
     * neither the pattern matches nor the browser accepts, read on the
     * languages, so on code points as the browser reads them: a pattern
     * over bytes is read under (*UTF) when it matches the same UTF-8
     * values so, else that value is not searched for.
     *
     * @throws ComplexityException|RegexException|\InvalidArgumentException
     *
     * @return array{?string, ?string, bool}
     */
    private function compareNotMatching(string $pattern, string $browser): array
    {
        $both = $this->solver->intersection(self::checkedAndAccepted($browser), $pattern, $this->options);
        $looser = $both->isEmpty ? null : $both->example;

        $codePoints = $this->parser->parse($pattern)->isUnicode() ? $pattern : $this->overCodePoints($pattern);
        if (null === $codePoints) {
            return [$looser, null, false];
        }

        $server = $this->solver->language($codePoints, $this->options);
        $client = $this->solver->language($browser, $this->options);
        $inputs = $this->solver->language('/^[^\r\n]+$/Du', $this->options);
        $stricter = $inputs->minus($server)->minus($client)->shortestExample();

        return [$looser, $stricter, true];
    }

    /**
     * The pattern over bytes read under (*UTF), when it matches the same
     * UTF-8 values so; null when it does not.
     *
     * @throws ComplexityException|RegexException
     */
    private function overCodePoints(string $pattern): ?string
    {
        $utf = $pattern[0].'(*UTF)'.substr($pattern, 1);

        return $this->solver->equivalent($pattern, $utf, $this->options)->isEquivalent ? $utf : null;
    }

    /**
     * The values the browser checks and accepts: not the empty value, and
     * none holding a line break.
     */
    private static function checkedAndAccepted(string $browser): string
    {
        return '/^(?![\s\S]*[\r\n])(?=[\s\S])'.substr($browser, 2);
    }

    /**
     * The values the browser accepts, and those it does not check: the
     * empty value, and any value holding a line break.
     */
    private static function acceptedOrUnchecked(string $browser): string
    {
        return '/^(?:'.substr($browser, 2, -3).'|$|[\s\S]*?[\r\n])/Du';
    }

    /**
     * A Length no non-empty value of the pattern fits: Regex does not check
     * an empty value, which passes both unless the Length or a NotBlank
     * refuses it. Length counts the characters of a valid UTF-8 value: the
     * pattern is read on them, the shortest value it accepts of at least
     * the least length the Length allows weighed against the most.
     *
     * @param array{class: string, property: string} $subject
     * @param list<Constraint>                       $constraints the constraints of the property
     *
     * @return list<ValidatorFinding>
     */
    private function checkLength(Regex $regex, Length $length, array $subject, array $constraints): array
    {
        if (!$regex->match || null !== $regex->normalizer || null !== $length->normalizer || 'UTF-8' !== $length->charset || !self::sharesAGroup($regex, $length)) {
            return [];
        }

        // Symfony 6.4 counts code points alone; 7.3 adds countUnit.
        $unit = get_object_vars($length)['countUnit'] ?? 'codepoints';
        if ('codepoints' !== $unit) {
            return [];
        }

        $pattern = (string) $regex->pattern;

        try {
            $shortest = $this->solver->intersection($pattern, '/\A[\s\S]{'.max(1, (int) $length->min).',}\z/u', $this->options)->example;
        } catch (ComplexityException|RegexException|\InvalidArgumentException $e) {
            return [self::finding($subject, $pattern, 'length.unchecked', 'notice', 'The Length constraint could not be compared with the pattern: '.$e->getMessage())];
        }

        // Length counts characters: the bytes of the value no UTF-8 sequence continues.
        if (null !== $shortest && (null === $length->max || LibraryPcre::matchAll('/[^\x80-\xBF]/', $shortest) <= $length->max)) {
            return [];
        }

        $allowed = match (true) {
            null === $length->max => \sprintf('at least %d', $length->min),
            null === $length->min => \sprintf('at most %d', $length->max),
            $length->min === $length->max => \sprintf('exactly %d', $length->min),
            default => \sprintf('%d to %d', $length->min, $length->max),
        };

        return [self::finding($subject, $pattern, 'length.contradiction', 'error', \sprintf(
            'No non-empty value the pattern accepts holds %s %s, as the Length constraint asks: %s',
            $allowed,
            1 === ($length->max ?? $length->min) ? 'character' : 'characters',
            self::whatPasses(self::rejectsEmpty($constraints, $regex, $length)),
        ))];
    }

    /**
     * The values a Choice offers that the pattern rejects: not those of a
     * match: false Choice, which it refuses, nor the empty value, which
     * Regex does not check.
     *
     * @param array{class: string, property: string} $subject
     *
     * @return list<ValidatorFinding>
     */
    private function checkChoice(Regex $regex, Choice $choice, array $subject): array
    {
        if (!$choice->match || $choice->multiple || null !== $choice->callback || !\is_array($choice->choices) || null !== $regex->normalizer || !self::sharesAGroup($regex, $choice)) {
            return [];
        }

        $pattern = (string) $regex->pattern;
        $rejected = [];
        foreach ($choice->choices as $value) {
            if (!\is_string($value) && !\is_int($value) || '' === $value) {
                continue;
            }

            $matched = $this->engine->test($pattern, (string) $value)->matched;
            if (null === $matched) {
                return [];
            }

            if ($matched !== $regex->match) {
                $rejected[] = (string) $value;
            }
        }

        if ([] === $rejected) {
            return [];
        }

        $all = \count($rejected) === \count($choice->choices);

        $message = \sprintf('%s the Choice constraint offers %s rejected by the pattern: %s.', $all ? 'Every value' : 'Some values', $all ? 'is' : 'are', implode(', ', array_map(self::quote(...), $rejected)));

        return [self::finding($subject, $pattern, 'choice.rejected', 'error', $message, ['example' => $rejected[0]])];
    }

    /**
     * Two Regex constraints of a group no non-empty value passes together.
     *
     * @param list<Regex>                            $regexes
     * @param array{class: string, property: string} $subject
     * @param list<Constraint>                       $constraints the constraints of the property
     *
     * @return list<ValidatorFinding>
     */
    private function checkConsistency(array $regexes, array $subject, array $constraints): array
    {
        $findings = [];
        $count = \count($regexes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $first = $regexes[$i];
                $second = $regexes[$j];
                if (!$first->match && !$second->match || null !== $first->normalizer || null !== $second->normalizer || !self::sharesAGroup($first, $second)) {
                    continue;
                }

                // A value passes a match: false constraint when it holds no
                // match: none passes both when the other's values all hold one.
                [$must, $mustNot] = $first->match ? [$first, $second] : [$second, $first];

                try {
                    $contradicts = $this->contradicts((string) $must->pattern, (string) $mustNot->pattern, $mustNot->match);
                } catch (ComplexityException|RegexException|\InvalidArgumentException $e) {
                    $contradicts = $e->getMessage();
                }

                if (\is_string($contradicts)) {
                    $findings[] = self::finding($subject, (string) $first->pattern, 'regex.unchecked', 'notice', \sprintf('The Regex constraints %s and %s could not be compared: %s', $first->pattern, $second->pattern, $contradicts));
                } elseif ($contradicts) {
                    $findings[] = self::finding($subject, (string) $first->pattern, 'regex.contradiction', 'error', \sprintf(
                        $mustNot->match ? 'No non-empty value matches both %s and %s: %s' : 'Every non-empty value %s matches also matches %s, which must not match: %s',
                        $mustNot->match ? $first->pattern : $must->pattern,
                        $mustNot->match ? $second->pattern : $mustNot->pattern,
                        self::whatPasses(self::rejectsEmpty($constraints, $first, $second)),
                    ));
                }
            }
        }

        return $findings;
    }

    /**
     * What a browser does wrong with the HTML pattern under v, refusing it
     * or reading it otherwise; null when it writes every delimiter. When
     * the parser cannot read it, only what makes a browser refuse it
     * whatever else it writes, and only when asked: the comparison says
     * the rest.
     *
     * @return list<array{offset: int, construct: string, message: string, refused: bool}>|null
     */
    private function vModeFindings(string $html, bool $structure = false): ?array
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (str_contains($html, $delimiter)) {
                continue;
            }

            try {
                return (new VModeCompatibility())->check($this->parser->parse($delimiter.$html.$delimiter.'u'));
            } catch (RegexException) {
                return $structure ? self::unbalanced($html) : [];
            }
        }

        return null;
    }

    /**
     * What makes a browser refuse the pattern under v whatever else it
     * writes: a class or a group never closed, a ")" that closes no group,
     * a "\" that ends it. Under v a "[" in a class opens a nested class, a
     * "]" closes one, and no "(" or ")" stands unescaped in a class, nor
     * any of them in a name, a property or a quantifier.
     *
     * @return list<array{offset: int, construct: string, message: string, refused: bool}>
     */
    private static function unbalanced(string $html): array
    {
        $classes = [];
        $groups = [];
        $length = \strlen($html);
        for ($i = 0; $i < $length; $i++) {
            $char = $html[$i];
            if ('\\' === $char) {
                if (++$i === $length) {
                    return [self::refusal($i - 1, 'escape', 'A "\\" that ends the pattern is a syntax error.')];
                }
            } elseif ('[' === $char) {
                $classes[] = $i;
            } elseif ([] !== $classes) {
                if (']' === $char) {
                    array_pop($classes);
                }
            } elseif ('(' === $char) {
                $groups[] = $i;
            } elseif (')' === $char && null === array_pop($groups)) {
                return [self::refusal($i, 'group', 'A ")" that closes no group is a syntax error.')];
            }
        }

        return match (true) {
            [] !== $classes => [self::refusal($classes[0], 'class', 'Under v a class that is never closed is a syntax error.')],
            [] !== $groups => [self::refusal($groups[0], 'group', 'A group that is never closed is a syntax error.')],
            default => [],
        };
    }

    /**
     * @return array{offset: int, construct: string, message: string, refused: bool}
     */
    private static function refusal(int $offset, string $construct, string $message): array
    {
        return ['offset' => $offset, 'construct' => $construct, 'message' => $message, 'refused' => true];
    }

    private function parses(string $pattern): bool
    {
        try {
            $this->parser->parse($pattern);

            return true;
        } catch (RegexException) {
            return false;
        }
    }

    /**
     * @param list<array{offset: int, construct: string, message: string, refused: bool}> $findings
     */
    private static function describe(array $findings): string
    {
        return implode(' ', array_map(static fn (array $r): string => \sprintf('[%s at %d] %s', $r['construct'], $r['offset'], $r['message']), $findings));
    }

    /**
     * The flags of the pattern, none when PHP refuses it.
     */
    private function flagsOf(string $pattern): string
    {
        try {
            return $this->parser->parse($pattern)->flags;
        } catch (RegexException) {
            return '';
        }
    }

    /**
     * The htmlPattern the html-pattern target writes for the pattern, one a
     * browser compiles, and whether the solver proved the browser reads it
     * as the server reads the pattern; null when it reads it otherwise.
     *
     * @return array{?string, bool}
     */
    private function suggest(string $pattern): array
    {
        return $this->suggested[$pattern] ??= $this->writeSuggestion($pattern);
    }

    /**
     * @return array{?string, bool}
     */
    private function writeSuggestion(string $pattern): array
    {
        try {
            $html = $this->transpiler->transpile($pattern, 'html-pattern')->pattern;
        } catch (\Throwable) {
            return [null, false];
        }

        if ([] !== $this->vModeFindings($html)) {
            return [null, false];
        }

        $browser = self::browserPattern($html);

        try {
            [$looser, $stricter] = $this->compareMatching($pattern, $browser);
        } catch (ComplexityException|RegexException|\InvalidArgumentException) {
            return [$html, false];
        }

        return null === $looser && null === $stricter ? [$html, true] : [null, false];
    }

    /**
     * The value rewritten so PCRE reads it as JavaScript does, over the
     * values an input holds: \w \d \b and their negations over ASCII, \s
     * over JavaScript's white space, \v the vertical tab alone, and none
     * of ".", \s, \S, \W, \D or a negated class taking a line break. A
     * line break the value writes itself (\n, \x0A, \P{L}...) is kept.
     */
    private static function asJavaScriptReadsIt(string $html): string
    {
        $word = '['.self::WORD.']';
        $outside = [
            'v' => '\x{B}',
            'w' => $word,
            'W' => '[^'.self::WORD.'\n\r]',
            'd' => '[0-9]',
            'D' => '[^0-9\n\r]',
            's' => '['.self::SPACE.']',
            'S' => '[^'.self::SPACE.'\n\r]',
            'b' => '(?:(?<='.$word.')(?!'.$word.')|(?<!'.$word.')(?='.$word.'))',
            'B' => '(?:(?<='.$word.')(?='.$word.')|(?<!'.$word.')(?!'.$word.'))',
        ];
        $inside = [
            'v' => '\x{B}',
            'w' => self::WORD,
            'W' => '\x{0}-\x{9}\x{B}\x{C}\x{E}-\x{2F}\x{3A}-\x{40}\x{5B}-\x{5E}\x{60}\x{7B}-\x{10FFFF}',
            'd' => '0-9',
            'D' => '\x{0}-\x{9}\x{B}\x{C}\x{E}-\x{2F}\x{3A}-\x{10FFFF}',
            's' => self::SPACE,
        ];

        $read = '';
        $depth = 0;
        $length = \strlen($html);
        for ($i = 0; $i < $length; $i++) {
            $char = $html[$i];
            if ('\\' === $char && $i + 1 < $length) {
                $next = $html[++$i];
                $read .= (0 === $depth ? $outside[$next] ?? null : $inside[$next] ?? null) ?? '\\'.$next;

                continue;
            }

            if ('[' === $char) {
                $depth++;
                if ($i + 1 < $length && '^' === $html[$i + 1]) {
                    $read .= '[^\n\r';
                    $i++;

                    continue;
                }
            } elseif (']' === $char && $depth > 0) {
                $depth--;
            } elseif ('.' === $char && 0 === $depth) {
                $read .= '[^\n\r\x{2028}\x{2029}]';

                continue;
            } elseif ('/' === $char) {
                $read .= '\/';

                continue;
            }

            $read .= $char;
        }

        return $read;
    }

    /**
     * The constraint, or the constraints a Sequentially runs.
     *
     * @return list<Constraint>
     */
    private static function flatten(Constraint $constraint): array
    {
        if (!$constraint instanceof Sequentially) {
            return [$constraint];
        }

        $flat = [];
        $nested = $constraint->constraints;
        foreach (\is_array($nested) ? $nested : [$nested] as $inner) {
            if ($inner instanceof Constraint) {
                $flat = [...$flat, ...self::flatten($inner)];
            }
        }

        return $flat;
    }

    /**
     * Whether no non-empty value both matches the first pattern and passes
     * the second: matches it, or not, as it must. Regex does not check an
     * empty value: when it is the only one the solver finds, the languages
     * tell whether another passes, both read on code points; a message
     * says why they cannot be.
     *
     * @throws ComplexityException|RegexException|\InvalidArgumentException
     */
    private function contradicts(string $must, string $other, bool $match): bool|string
    {
        $example = $match
            ? $this->solver->intersection($must, $other, $this->options)->example
            : $this->solver->subsetOf($must, $other, $this->options)->counterExample;
        if ('' !== $example) {
            return null === $example;
        }

        $unicode = $this->parser->parse($must)->isUnicode();
        if ($unicode !== $this->parser->parse($other)->isUnicode()) {
            $read = $this->overCodePoints($unicode ? $other : $must);
            if (null === $read) {
                return 'one reads bytes and the other characters, and the only value found both pass is the empty one, which Regex does not check.';
            }

            [$must, $other] = $unicode ? [$must, $read] : [$read, $other];
        }

        $mustLanguage = $this->solver->language($must, $this->options);
        $otherLanguage = $this->solver->language($other, $this->options);

        return 0 === ($match ? $mustLanguage->intersect($otherLanguage) : $mustLanguage->minus($otherLanguage))->maxLength();
    }

    /**
     * What passes the constraints of a contradiction: nothing, or an empty
     * value unless a constraint refuses it.
     */
    private static function whatPasses(bool $emptyRejected): string
    {
        return $emptyRejected ? 'no value passes both.' : 'only an empty value, which Regex does not check, can pass both.';
    }

    /**
     * Whether a constraint of the property that runs with each given one
     * refuses an empty value: a NotBlank, or a Length of a least length,
     * neither normalizing the value first.
     *
     * @param list<Constraint> $constraints
     */
    private static function rejectsEmpty(array $constraints, Constraint ...$with): bool
    {
        foreach ($constraints as $constraint) {
            $refuses = ($constraint instanceof NotBlank && null === $constraint->normalizer)
                || ($constraint instanceof Length && null === $constraint->normalizer && ($constraint->min ?? 0) > 0);
            if ($refuses && [] === array_filter($with, static fn (Constraint $other): bool => !self::sharesAGroup($constraint, $other))) {
                return true;
            }
        }

        return false;
    }

    private static function sharesAGroup(Constraint $first, Constraint $second): bool
    {
        return [] !== array_intersect((array) $first->groups, (array) $second->groups);
    }

    /**
     * @param array{class: string, property: string}                             $subject
     * @param 'error'|'notice'|'warning'                                         $severity
     * @param array{htmlPattern?: string, example?: string, suggestion?: string} $extra
     *
     * @return ValidatorFinding
     */
    private static function finding(array $subject, string $pattern, string $check, string $severity, string $message, array $extra = []): array
    {
        return ['class' => $subject['class'], 'property' => $subject['property'], 'pattern' => $pattern, 'check' => $check, 'severity' => $severity, 'message' => $message] + $extra;
    }

    /**
     * The value between double quotes, as the console reports spell text:
     * a backslash doubled, a control or hidden character written as an
     * escape, \x{2028} or \xFF.
     */
    private static function quote(string $value): string
    {
        return '"'.DisplayEscaper::escapeText($value).'"';
    }
}
