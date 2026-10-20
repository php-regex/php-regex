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

namespace PHPRegex\Tests\Integration\Transpiler\Oracle;

/**
 * Random PCRE patterns over the constructs the transpiler targets read:
 * literals and escapes, classes, the dot, anchors and assertions, every
 * kind of group, inline flags, backreferences, alternation and quantifiers,
 * under every combination of the flags they map. The draw is seeded, so a
 * run is the same run everywhere; a pattern PCRE refuses is left out.
 */
final class PatternGenerator
{
    private const ATOMS = [
        'a', 'b', 'c', 'x', 'A', 'B', 'K', 'k', 's', 'ab', 'abc', '1', '_', '-', ' ', '#', '/', '\\/', '\\.', '\\-', '\\ ', '\\#',
        '\\n', '\\r', '\\t', '\\f', '\\e', '\\x41', '\\x{6b}', '\\101', '\\0', '\\cA', '\\c?', '\\Q.a\\E', "\n",
        '.', '.', '\\d', '\\D', '\\w', '\\W', '\\s', '\\S', '\\h', '\\H', '\\v', '\\V', '\\R', '\\N',
        '[abc]', '[^a]', '[a-c]', '[^a-c]', '[\\d_]', '[^\\s]', '[\\w-]', '[.]', '[\\n]', '[^\\n]', '[\\r\\n]', '[ab\\]]',
        '[[:alpha:]]', '[[:^digit:]]', '[\\W]', '[\\S\\d]', '[^\\w\\s]', '[k]', '[A-Z]', '[\\x{e9}]', '[-a]', '[a-]', '[\\^a]',
    ];

    private const UNICODE_ATOMS = [
        'é', 'É', 'ß', 'ſ', '٣', '😀', '\\x{e9}', '\\x{212A}', '\\x{1F600}', '\\x{2028}', '\\x{a0}', '\\x{85}',
        '\\p{L}', '\\p{Lu}', '\\p{Ll}', '\\pN', '\\P{L}', '\\p{Nd}', '\\p{Greek}', '\\p{Latin}', '\\p{Han}', '\\p{Zs}', '\\X',
        '[é-ù]', '[^é]', '[\\p{L}\\d]', '[\\x{2028}\\x{2029}]', '[ſ]', '[\\x{212A}]',
    ];

    private const ANCHORS = ['^', '$', '\\A', '\\z', '\\Z', '\\b', '\\B', '\\G'];

    private const QUANTIFIERS = ['*', '+', '?', '{2}', '{1,3}', '{2,}', '{0}', '{0,1}', '*?', '+?', '??', '{1,2}?', '*+', '++', '?+'];

    private const FLAGS = ['', '', '', 'i', 'm', 's', 'x', 'u', 'U', 'D', 'iu', 'mu', 'su', 'ms', 'im', 'xu', 'Uu', 'Du', 'imsu', 'ix', 'mD'];

    private const INLINE_FLAGS = ['i', 'm', 's', 'x', 'U', '-i', 'i-s', '^', 'J', 'n', 'xx'];

    private int $state;

    private int $groups = 0;

    /**
     * @var list<string>
     */
    private array $names = [];

    private bool $unicode = false;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0x7FFFFFFF;
    }

    /**
     * @return list<string> $count patterns PCRE compiles, delimited
     */
    public function generate(int $count): array
    {
        $patterns = [];
        $attempts = 0;
        while (\count($patterns) < $count && $attempts++ < $count * 10) {
            $flags = $this->pick(self::FLAGS);
            $this->unicode = str_contains($flags, 'u');
            $this->groups = 0;
            $this->names = [];
            $pattern = '/'.$this->alternation(3).'/'.$flags;
            if (false !== @preg_match($pattern, '')) {
                $patterns[$pattern] = true;
            }
        }

        return array_keys($patterns);
    }

    private function alternation(int $depth): string
    {
        $branches = [$this->sequence($depth)];
        while ($this->chance(15)) {
            $branches[] = $this->sequence($depth);
        }

        return implode('|', $branches);
    }

    private function sequence(int $depth): string
    {
        $length = $this->number(1, 4);
        $sequence = '';
        for ($i = 0; $i < $length; $i++) {
            $sequence .= $this->item($depth);
        }

        return $sequence;
    }

    private function item(int $depth): string
    {
        $roll = $this->number(0, 99);
        if ($roll < 10) {
            return $this->pick(self::ANCHORS);
        }

        if ($roll < 18 && $depth > 0) {
            return $this->group($depth - 1).($this->chance(30) ? $this->pick(self::QUANTIFIERS) : '');
        }

        if ($roll < 22 && $this->groups > 0) {
            $number = $this->number(1, $this->groups);

            return $this->pick(['\\'.$number, '\\g'.$number, '\\g{'.$number.'}', '\\g{-1}', [] === $this->names ? '\\'.$number : '\\k<'.$this->pick($this->names).'>', [] === $this->names ? '\\'.$number : '(?P='.$this->pick($this->names).')']);
        }

        $atom = $this->unicode && $this->chance(25) ? $this->pick(self::UNICODE_ATOMS) : $this->pick(self::ATOMS);

        return $atom.($this->chance(30) ? $this->pick(self::QUANTIFIERS) : '');
    }

    private function group(int $depth): string
    {
        $body = $this->alternation($depth);
        $kind = $this->number(0, 13);

        switch ($kind) {
            case 0:
            case 1:
            case 2:
                $this->groups++;

                return '('.$body.')';
            case 3:
                return '(?:'.$body.')';
            case 4:
                $this->groups++;
                $name = 'n'.\count($this->names);
                $this->names[] = $name;

                return $this->pick(['(?<'.$name.'>', '(?P<'.$name.'>', "(?'".$name."'"]).$body.')';
            case 5:
                return '(?='.$body.')';
            case 6:
                return '(?!'.$body.')';
            case 7:
                return '(?<='.$this->pick(self::ATOMS).')';
            case 8:
                return '(?<!'.$this->pick(self::ATOMS).')';
            case 9:
                return '(?>'.$body.')';
            case 10:
                return '(?'.$this->pick(self::INLINE_FLAGS).':'.$body.')';
            case 11:
                return '(?'.$this->pick(self::INLINE_FLAGS).')';
            case 12:
                return '(?|'.$body.')';
            default:
                return '(?:'.$body.')';
        }
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $values
     *
     * @return T
     */
    private function pick(array $values): mixed
    {
        return $values[$this->number(0, \count($values) - 1)];
    }

    private function chance(int $percent): bool
    {
        return $this->number(0, 99) < $percent;
    }

    private function number(int $min, int $max): int
    {
        // A linear congruential draw: the same on every PHP and platform.
        $this->state = (1103515245 * $this->state + 12345) & 0x7FFFFFFF;

        return $min + ($this->state >> 8) % ($max - $min + 1);
    }
}
