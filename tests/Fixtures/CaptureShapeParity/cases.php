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

/*
 * The capture-shape parity corpus: preg_match() cases replayed on the engine.
 * Every subject matches its pattern; the shape inferred for the pattern must
 * hold the $matches the engine writes for it, under each flag set.
 *
 * Three sources:
 *  - "analyzer": the engine rows of CaptureShapeAnalyzerTest::provideEngineRows();
 *  - "php-src <file>": the preg_match() rows of php_pcre_comprehensive.php whose
 *    subject matches (the rows of one pattern share one entry);
 *  - "parity: <topic>": written for this corpus.
 *
 * @return list<array{source: string, pattern: string, subjects: non-empty-list<string>}>
 */
return [
    // Engine rows of the capture-shape analyzer test.
    ['source' => 'analyzer', 'pattern' => '/(a)(z)?/', 'subjects' => ['a', 'az']],
    ['source' => 'analyzer', 'pattern' => '/(z)?(a)/', 'subjects' => ['a', 'za']],
    ['source' => 'analyzer', 'pattern' => '/(a)(x)?(b)/', 'subjects' => ['ab', 'axb']],
    ['source' => 'analyzer', 'pattern' => '/(a)|b/', 'subjects' => ['a', 'b']],
    ['source' => 'analyzer', 'pattern' => '/(?=(a))a/', 'subjects' => ['a']],
    ['source' => 'analyzer', 'pattern' => '/(?!(b))a/', 'subjects' => ['a']],
    ['source' => 'analyzer', 'pattern' => '/(?!(b))(a)/', 'subjects' => ['a']],
    ['source' => 'analyzer', 'pattern' => '/(?(DEFINE)(x))a(?1)/', 'subjects' => ['ax']],
    ['source' => 'analyzer', 'pattern' => '/(?|(a)|(b))/', 'subjects' => ['a', 'b']],
    ['source' => 'analyzer', 'pattern' => '/(?|(a)(b)|(c))/', 'subjects' => ['ab', 'c']],
    ['source' => 'analyzer', 'pattern' => '/((a)|b)+/', 'subjects' => ['ab', 'ba', 'bb']],
    ['source' => 'analyzer', 'pattern' => '/(?<n>a)(b)/', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'subjects' => ['a', 'b']],
    ['source' => 'analyzer', 'pattern' => '/(a){0}b/', 'subjects' => ['b']],
    ['source' => 'analyzer', 'pattern' => '/^(\\d{3})-(\\d{4})$/', 'subjects' => ['555-1234']],
    ['source' => 'analyzer', 'pattern' => '/(foo|bar)(baz)?/i', 'subjects' => ['FOObaz', 'bar']],
    ['source' => 'analyzer', 'pattern' => '/(a b)/x', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/(?<y>\\d{4})-(?<m>\\d\\d)?/', 'subjects' => ['2026-10', '2026-']],
    ['source' => 'analyzer', 'pattern' => '/(x)?(?(1)(a)|(b))/', 'subjects' => ['xa', 'b']],
    ['source' => 'analyzer', 'pattern' => '/(a(?1)?b)/', 'subjects' => ['ab', 'aabb']],
    ['source' => 'analyzer', 'pattern' => '/a\\K(b)/', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/(*MARK:m)(a)|(b)/', 'subjects' => ['a', 'b']],
    ['source' => 'analyzer', 'pattern' => '/(é)+/', 'subjects' => ['éé']],
    ['source' => 'analyzer', 'pattern' => '/(\\x41|\\n)/', 'subjects' => ['A', "\n"]],
    ['source' => 'analyzer', 'pattern' => '/()/', 'subjects' => ['']],
    ['source' => 'analyzer', 'pattern' => '/(a*)(b)?/', 'subjects' => ['', 'aab']],
    ['source' => 'analyzer', 'pattern' => '/(?<n>a)?(?<m>z)?/', 'subjects' => ['', 'z']],
    ['source' => 'analyzer', 'pattern' => '/(it\'s)/', 'subjects' => ['it\'s']],
    ['source' => 'analyzer', 'pattern' => '/(a(*ACCEPT)b)c/', 'subjects' => ['a', 'abc']],
    ['source' => 'analyzer', 'pattern' => '/(\\x{e9})/u', 'subjects' => ['é']],
    ['source' => 'analyzer', 'pattern' => '/(\\b)a/i', 'subjects' => ['A']],
    ['source' => 'analyzer', 'pattern' => '/(?<MARK>a)(*MARK:x)b/', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/(?<MARK>a)|(*MARK:x)b/', 'subjects' => ['a', 'b']],
    ['source' => 'analyzer', 'pattern' => '/(?<MARK>a)(?<y>b)(*MARK:x)c/', 'subjects' => ['abc']],
    ['source' => 'analyzer', 'pattern' => '/(?<MARK>a)?(b)(?:(*MARK:x)c|d|(*MARK:y)e)/', 'subjects' => ['bc', 'bd', 'be', 'abc', 'abd', 'abe']],
    ['source' => 'analyzer', 'pattern' => '/(?<MARK>a)/', 'subjects' => ['a']],
    ['source' => 'analyzer', 'pattern' => '/(?<x>a)(*MARK:m)b/', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/((*ACCEPT)a)/', 'subjects' => ['x', 'a']],
    ['source' => 'analyzer', 'pattern' => '/(?|(x)|(?<a>y))/', 'subjects' => ['x', 'y']],
    ['source' => 'analyzer', 'pattern' => '/(?|(?<a>x)|(y))/', 'subjects' => ['x', 'y']],
    ['source' => 'analyzer', 'pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'subjects' => ['ax', 'bx']],
    ['source' => 'analyzer', 'pattern' => '/(?J)(?<n>a)(?<n>b)/', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'subjects' => ['ac', 'azc']],
    ['source' => 'analyzer', 'pattern' => '/(?J)(?:(?<n>a)|(?<n>b))?(x)/', 'subjects' => ['x', 'ax']],
    ['source' => 'analyzer', 'pattern' => '/(a)(?<x>b)/n', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/(?n)(a)(?<x>b)/', 'subjects' => ['ab']],
    ['source' => 'analyzer', 'pattern' => '/(?<p>a)(b)(?<q>c)/n', 'subjects' => ['abc']],
    ['source' => 'analyzer', 'pattern' => '/(?(DEFINE)(?<d>x))(a)(?&d)/', 'subjects' => ['ax']],
    ['source' => 'analyzer', 'pattern' => '/(?|(a)|(b)(c))(d)/', 'subjects' => ['ad', 'bcd']],

    // preg_match() rows of the php-src PCRE tests (php_pcre_comprehensive.php).
    ['source' => 'php-src bug37911.phpt', 'pattern' => '|(?P<name>blub)|', 'subjects' => ['bla blub blah']],
    ['source' => 'php-src bug40195.phpt', 'pattern' => '@^(/([a-z]*))*$@', 'subjects' => ['//abcde']],
    ['source' => 'php-src bug40195.phpt', 'pattern' => '@^(/(?:[a-z]*))*$@', 'subjects' => ['//abcde']],
    ['source' => 'php-src bug40195.phpt', 'pattern' => '@^(/([a-z]+))+$@', 'subjects' => ['/a/abcde']],
    ['source' => 'php-src bug40195.phpt', 'pattern' => '@^(/(?:[a-z]+))+$@', 'subjects' => ['/a/abcde']],
    ['source' => 'php-src bug47229.phpt', 'pattern' => '/[a\\-c]+/', 'subjects' => ['a---b', 'a-']],
    ['source' => 'php-src bug61780.phpt', 'pattern' => '/(a)?([a-z]*)(\\d*)/', 'subjects' => ['123']],
    ['source' => 'php-src bug61780_1.phpt', 'pattern' => '/(4)?(2)?\\d/', 'subjects' => ['23456']],
    ['source' => 'php-src bug61780_2.phpt', 'pattern' => '/(?<a>4)?(?<b>2)?\\d/', 'subjects' => ['23456']],
    ['source' => 'php-src bug67238.phpt', 'pattern' => '/a{1,3}b/U', 'subjects' => ['ab']],
    ['source' => 'php-src bug73612.phpt', 'pattern' => '/./', 'subjects' => ['x', 'a']],
    ['source' => 'php-src bug74873.phpt', 'pattern' => '/\\S+/', 'subjects' => ['foo bar']],
    ['source' => 'php-src bug76512.phpt', 'pattern' => '/\\w/u', 'subjects' => ['ä']],
    ['source' => 'php-src bug77827.phpt', 'pattern' => "/foo/i\n", 'subjects' => ['FOO']],
    ['source' => 'php-src bug78272.phpt', 'pattern' => '/abc/', 'subjects' => ['abcde']],
    ['source' => 'php-src bug78853.phpt', 'pattern' => '/^|\\d{1,2}$/', 'subjects' => ['7']],
    ['source' => 'php-src bug79257.phpt', 'pattern' => '/(?J)(?:(?<g>foo)|(?<g>bar))/', 'subjects' => ['foo']],
    ['source' => 'php-src bug79257.phpt', 'pattern' => '/(?J)(?:(?<g>foo)|(?<g>bar))(?<h>baz)/', 'subjects' => ['foobaz']],
    ['source' => 'php-src bug79846.phpt', 'pattern' => '/([a-z]+_[a-z]+_*[a-z]+)_?(d+)?/', 'subjects' => ['component_phase_1']],
    ['source' => 'php-src bug81424a.phpt', 'pattern' => '/(?P<size>\\d+)m|M/', 'subjects' => ['4M']],
    ['source' => 'php-src delimiters.phpt', 'pattern' => '@@', 'subjects' => ['']],
    ['source' => 'php-src delimiters.phpt', 'pattern' => '<>', 'subjects' => ['']],
    ['source' => 'php-src delimiters.phpt', 'pattern' => '@\\@\\@@', 'subjects' => ['@@']],
    ['source' => 'php-src match_flags2.phpt', 'pattern' => '/x(.)/', 'subjects' => ['fjszxax']],
    ['source' => 'php-src match_flags2.phpt', 'pattern' => '/(.)x/', 'subjects' => ['fjszxax']],
    ['source' => 'php-src match_flags2.phpt', 'pattern' => '/(?P<capt1>.)(x)(?P<letsmix>\\S+)/', 'subjects' => ['fjszxax']],
    ['source' => 'php-src match_flags3.phpt', 'pattern' => '/\\d+/', 'subjects' => ['123 456 789 012']],
    ['source' => 'php-src pcre_anchored.phpt', 'pattern' => '/\\PN+/', 'subjects' => ['123abc']],
    ['source' => 'php-src pcre_extended.phpt', 'pattern' => '/a e i o u/x', 'subjects' => ['aeiou']],
    ['source' => 'php-src pcre_extended.phpt', 'pattern' => "/a e\ni\to\nu/x", 'subjects' => ['aeiou']],
    ['source' => 'php-src gh11956.phpt', 'pattern' => '/<(\\w+)[\\s\\w\\-]+ id="S44_i89ew">/', 'subjects' => ['<br><div id="S44_i89ew">']],
    ['source' => 'php-src oss-fuzz-65021.phpt', 'pattern' => '>foo>i', 'subjects' => ['FOO']],
    ['source' => 'php-src study.phpt', 'pattern' => '/(?:(?:(?:(?:(?:(.))))))/  S', 'subjects' => ['aeiou']],
    ['source' => 'php-src study.phpt', 'pattern' => '/(?:(?:(?:(?:(?:(.))))))/', 'subjects' => ['aeiou']],
    ['source' => 'php-src study.phpt', 'pattern' => '/(..)((?:(.)|.|.|.|u))/S', 'subjects' => ['aeiou']],
    ['source' => 'php-src study.phpt', 'pattern' => '/^aeiou$/S', 'subjects' => ['aeiou']],
    ['source' => 'php-src study.phpt', 'pattern' => '/aeiou/S', 'subjects' => ['aeiou']],
    ['source' => 'php-src ungreedy.phpt', 'pattern' => '/<.*>/', 'subjects' => ['<aa> <bb> <cc>']],
    ['source' => 'php-src ungreedy.phpt', 'pattern' => '/<.*>/U', 'subjects' => ['<aa> <bb> <cc>']],
    ['source' => 'php-src ungreedy.phpt', 'pattern' => '/(?U)<.*>/', 'subjects' => ['<aa> <bb> <cc>']],
    ['source' => 'php-src preg_match_basic.phpt', 'pattern' => '/^[hH]ello,\\s/', 'subjects' => ['Hello, world. [*], this is  a string']],
    ['source' => 'php-src preg_match_basic.phpt', 'pattern' => '/\\[\\*\\],\\s(.*)/', 'subjects' => ['Hello, world. [*], this is  a string']],
    ['source' => 'php-src preg_match_basic_002.phpt', 'pattern' => '/M(.*)/', 'subjects' => ["My\nName\nIs\nStrange"]],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/AskZ/iur', 'subjects' => ['AskZ', 'aSKz']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/AskZ/iu', 'subjects' => ['AskZ', 'aSKz']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/A\\x{17f}\\x{212a}Z/iu', 'subjects' => ['AskZ']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[AskZ]+/iur', 'subjects' => ['AskZ', 'aSKz', 'Au{17f}kZ', 'Asu{212a}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[AskZ]+/iu', 'subjects' => ['AskZ', 'aSKz', 'Au{17f}kZ', 'Asu{212a}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[\\x{17f}\\x{212a}]+/iu', 'subjects' => ['AskZ']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^s]+/iur', 'subjects' => ['Au{17f}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^s]+/iu', 'subjects' => ['Au{17f}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^k]+/iur', 'subjects' => ['Au{212a}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^k]+/iu', 'subjects' => ['Au{212a}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^sk]+/iur', 'subjects' => ['Au{17f}u{212a}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^sk]+/iu', 'subjects' => ['Au{17f}u{212a}Z']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^\\x{17f}]+/iur', 'subjects' => ['AsSZ']],
    ['source' => 'php-src preg_match_caseless_restrict.phpt', 'pattern' => '/[^\\x{17f}]+/iu', 'subjects' => ['AsSZ']],
    ['source' => 'php-src preg_match_latin.phpt', 'pattern' => '/^[\\w\\p{Cyrillic}\\s\\-\']+$/u', 'subjects' => ['latin', 'кириллица']],
    ['source' => 'php-src preg_match_latin.phpt', 'pattern' => '/^[\\w\\s\\-\']+$/u', 'subjects' => ['latin']],
    ['source' => 'php-src preg_match_non_capture.phpt', 'pattern' => '/.(.)./n', 'subjects' => ['abc']],
    ['source' => 'php-src preg_match_non_capture.phpt', 'pattern' => '/.(?P<test>.)./n', 'subjects' => ['abc']],
    ['source' => 'php-src preg_match_variation1.phpt', 'pattern' => '/[\\-\\+]?[0-9\\.]*/', 'subjects' => ['-1']],
    ['source' => 'php-src invalid_utf8_offset.phpt', 'pattern' => '~.*~u', 'subjects' => ['é uma string utf8 bem formada']],
    ['source' => 'php-src null_bytes.phpt', 'pattern' => "/\x00/i", 'subjects' => ["\x00"]],
    ['source' => 'php-src null_bytes.phpt', 'pattern' => "/\\\x00/i", 'subjects' => ["\\\x00"]],
    ['source' => 'php-src null_bytes.phpt', 'pattern' => "[\x00]i", 'subjects' => ["\x00"]],
    ['source' => 'php-src null_bytes.phpt', 'pattern' => "[\\\x00]i", 'subjects' => ["\\\x00"]],
    ['source' => 'php-src null_bytes.phpt', 'pattern' => "/abc\x00def/", 'subjects' => ["abc\x00def"]],
    ['source' => 'php-src null_bytes.phpt', 'pattern' => "[abc\x00def]", 'subjects' => ["abc\x00def"]],

    // Written for this corpus: the constructs a capture-shape inference has
    // to read. Each subject was run through preg_match() (PHP 8.4.26, PCRE2 10.49).

    // Optional and nested groups.
    ['source' => 'parity: optional', 'pattern' => '/(a(b)?)c/', 'subjects' => ['ac', 'abc']],
    ['source' => 'parity: optional', 'pattern' => '/((a)(b)?)?c/', 'subjects' => ['c', 'ac', 'abc']],
    ['source' => 'parity: optional', 'pattern' => '/(a)?(b)?(c)?/', 'subjects' => ['', 'b', 'ac']],
    ['source' => 'parity: optional', 'pattern' => '/(a)??b/', 'subjects' => ['b', 'ab']],
    ['source' => 'parity: optional', 'pattern' => '/(a)?+b/', 'subjects' => ['b', 'ab']],
    ['source' => 'parity: optional', 'pattern' => '/(?>(a)?)b/', 'subjects' => ['b', 'ab']],
    ['source' => 'parity: optional', 'pattern' => '/((((a))))/', 'subjects' => ['a']],
    ['source' => 'parity: optional', 'pattern' => '/()()/', 'subjects' => ['']],
    ['source' => 'parity: optional', 'pattern' => '/(|)/', 'subjects' => ['']],

    // Repeated groups keep the value of their last iteration.
    ['source' => 'parity: repeat', 'pattern' => '/(?:(a)|b)*/', 'subjects' => ['', 'ab', 'ba', 'abb']],
    ['source' => 'parity: repeat', 'pattern' => '/(?:(a)|(b))+/', 'subjects' => ['a', 'ab', 'ba']],
    ['source' => 'parity: repeat', 'pattern' => '/(a)*/', 'subjects' => ['', 'aa']],
    ['source' => 'parity: repeat', 'pattern' => '/(a){2}/', 'subjects' => ['aa']],
    ['source' => 'parity: repeat', 'pattern' => '/(a){1,3}/', 'subjects' => ['a', 'aaa']],
    ['source' => 'parity: repeat', 'pattern' => '/(a|b){0,2}/', 'subjects' => ['', 'ab']],
    ['source' => 'parity: repeat', 'pattern' => '/(?<n>a)+/', 'subjects' => ['aa']],
    ['source' => 'parity: repeat', 'pattern' => '/(a+)/U', 'subjects' => ['aa']],

    // Alternation: a trailing group the match skips is left out.
    ['source' => 'parity: alternation', 'pattern' => '/(a)|(b)|(c)/', 'subjects' => ['a', 'b', 'c']],
    ['source' => 'parity: alternation', 'pattern' => '/(?<x>a)|(?<y>b)/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: alternation', 'pattern' => '/(a|)/', 'subjects' => ['', 'a']],
    ['source' => 'parity: alternation', 'pattern' => '/(a)|/', 'subjects' => ['', 'a']],
    ['source' => 'parity: alternation', 'pattern' => '/(a)|(?<n>b)/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: alternation', 'pattern' => '/^(?:(a)|b)$/m', 'subjects' => ['a', "x\nb"]],

    // Branch reset.
    ['source' => 'parity: branch reset', 'pattern' => '/(?|(a)(b)?|(c))/', 'subjects' => ['a', 'ab', 'c']],
    ['source' => 'parity: branch reset', 'pattern' => '/(?|(?<n>a)|(?<n>b))/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: branch reset', 'pattern' => '/(?|(a)|(b))(?|(c)|(d))/', 'subjects' => ['ac', 'bd']],
    ['source' => 'parity: branch reset', 'pattern' => '/(?|a|(b))/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: branch reset', 'pattern' => '/(?|(a)|(?|(b)|(c)))/', 'subjects' => ['a', 'b', 'c']],
    ['source' => 'parity: branch reset', 'pattern' => '/(?|(a)|(b)c)(d)?/', 'subjects' => ['a', 'bcd']],

    // Names, back references and duplicate names.
    ['source' => 'parity: names', 'pattern' => "/(?'q'a)(?P<p>b)/", 'subjects' => ['ab']],
    ['source' => 'parity: names', 'pattern' => '/(?<n>a)\k<n>/', 'subjects' => ['aa']],
    ['source' => 'parity: names', 'pattern' => '/(?P<n>a)(?P=n)/', 'subjects' => ['aa']],
    ['source' => 'parity: names', 'pattern' => '/(a)\1/', 'subjects' => ['aa']],
    ['source' => 'parity: names', 'pattern' => '/(a)\g{-1}/', 'subjects' => ['aa']],
    ['source' => 'parity: names', 'pattern' => '/(?<n>a)|(?<n>b)/J', 'subjects' => ['a', 'b']],
    ['source' => 'parity: names', 'pattern' => '/(?J)(?<n>a)?(?<n>b)/', 'subjects' => ['b', 'ab']],
    // preg_match() gives n the group set, preg_match_all() in pattern order
    // the list of the last group bearing the name, here one never set:
    // preg_match('/(?J)(?<n>a)(?<n>z){0}/', 'a') -> n => 'a';
    // preg_match_all(…, 'aa') -> n => ["",""] (PHP 8.4.26, PCRE2 10.49).
    ['source' => 'parity: names', 'pattern' => '/(?J)(?<n>a)(?<n>z){0}/', 'subjects' => ['a']],

    // No auto capture: /n and (?n) leave the unnamed groups out.
    ['source' => 'parity: no auto capture', 'pattern' => '/(a)(b)/n', 'subjects' => ['ab']],
    ['source' => 'parity: no auto capture', 'pattern' => '/(?n)(a)(?-n)(b)/', 'subjects' => ['ab']],
    ['source' => 'parity: no auto capture', 'pattern' => '/(?n:(a))(b)/', 'subjects' => ['ab']],
    ['source' => 'parity: no auto capture', 'pattern' => '/(?n)(?<x>a)|(b)/', 'subjects' => ['a', 'b']],

    // Extended mode and comments.
    ['source' => 'parity: extended', 'pattern' => '#(a)(b)#x', 'subjects' => ['ab']],
    ['source' => 'parity: extended', 'pattern' => "/(a) # one\n (b) # two\n/x", 'subjects' => ['ab']],
    ['source' => 'parity: extended', 'pattern' => '/(a)#(b)/x', 'subjects' => ['a']],
    ['source' => 'parity: extended', 'pattern' => '/(a)[#](b)/x', 'subjects' => ['a#b']],
    ['source' => 'parity: extended', 'pattern' => '/(a)\#(b)/x', 'subjects' => ['a#b']],
    ['source' => 'parity: extended', 'pattern' => '/(a)(?#c)(b)/', 'subjects' => ['ab']],
    ['source' => 'parity: extended', 'pattern' => '/(a b)(?x)(c d)/', 'subjects' => ['a bcd']],
    ['source' => 'parity: extended', 'pattern' => '/(a)(?-x) (b)/x', 'subjects' => ['a b']],
    ['source' => 'parity: extended', 'pattern' => '/(a) (b)/xx', 'subjects' => ['ab']],
    ['source' => 'parity: extended', 'pattern' => '/([a b])/xx', 'subjects' => ['a', 'b']],
    ['source' => 'parity: extended', 'pattern' => '/(?x)(a) (b)/', 'subjects' => ['ab']],

    // Quoted text.
    ['source' => 'parity: quoting', 'pattern' => '/\Q(a)\E/', 'subjects' => ['(a)']],
    ['source' => 'parity: quoting', 'pattern' => '/\Q(a)\E(b)/', 'subjects' => ['(a)b']],
    ['source' => 'parity: quoting', 'pattern' => '/(\Q)\E)/', 'subjects' => [')']],
    ['source' => 'parity: quoting', 'pattern' => '/\Q(a)/', 'subjects' => ['(a)']],
    ['source' => 'parity: quoting', 'pattern' => '/[\Q]\E](a)/', 'subjects' => [']a']],
    ['source' => 'parity: quoting', 'pattern' => '/(a)\E/', 'subjects' => ['a']],

    // Caseless matching, inline and as a modifier, ASCII and beyond.
    ['source' => 'parity: caseless', 'pattern' => '/(?i)(a)/', 'subjects' => ['a', 'A']],
    ['source' => 'parity: caseless', 'pattern' => '/(?i:a)(b)/', 'subjects' => ['Ab']],
    ['source' => 'parity: caseless', 'pattern' => '/((?i)a)b/', 'subjects' => ['Ab']],
    ['source' => 'parity: caseless', 'pattern' => '/(?i)(a)(?-i)(b)/', 'subjects' => ['Ab']],
    ['source' => 'parity: caseless', 'pattern' => '/(a)(?i)(b)/', 'subjects' => ['aB']],
    ['source' => 'parity: caseless', 'pattern' => '/(?i)(a)(?^)(b)/', 'subjects' => ['Ab']],
    ['source' => 'parity: caseless', 'pattern' => '/(?i:(a)|(b))/', 'subjects' => ['A', 'B']],
    ['source' => 'parity: caseless', 'pattern' => '/(k)/iu', 'subjects' => ['k', 'K', "\u{212A}"]],
    ['source' => 'parity: caseless', 'pattern' => '/(k)/iur', 'subjects' => ['k', 'K']],
    ['source' => 'parity: caseless', 'pattern' => '/(?r)(k)/iu', 'subjects' => ['K']],
    ['source' => 'parity: caseless', 'pattern' => '/(s)/iu', 'subjects' => ['S', "\u{17F}"]],
    ['source' => 'parity: caseless', 'pattern' => '/(é)/iu', 'subjects' => ['é', 'É']],
    ['source' => 'parity: caseless', 'pattern' => '/(é)/i', 'subjects' => ['é']],
    ['source' => 'parity: caseless', 'pattern' => '/(*UTF)(é)/i', 'subjects' => ['É']],
    ['source' => 'parity: caseless', 'pattern' => "/(?i)(\u{1C5})/u", 'subjects' => ["\u{1C4}", "\u{1C5}", "\u{1C6}"]],
    ['source' => 'parity: caseless', 'pattern' => '/([a-z]+)/i', 'subjects' => ['ABC']],

    // Keep: \K moves the start of the whole match.
    ['source' => 'parity: keep', 'pattern' => '/(a)\K(b)/', 'subjects' => ['ab']],
    ['source' => 'parity: keep', 'pattern' => '/(a\Kb)/', 'subjects' => ['ab']],
    ['source' => 'parity: keep', 'pattern' => '/(?:a\K)+(b)/', 'subjects' => ['ab', 'aab']],
    ['source' => 'parity: keep', 'pattern' => '/a(?:\Kb|c)/', 'subjects' => ['ab', 'ac']],

    // Groups inside lookarounds.
    ['source' => 'parity: lookaround', 'pattern' => '/(?=(a))/', 'subjects' => ['a']],
    ['source' => 'parity: lookaround', 'pattern' => '/(?<=(a))b/', 'subjects' => ['ab']],
    ['source' => 'parity: lookaround', 'pattern' => '/(?<!(x))a/', 'subjects' => ['a']],
    ['source' => 'parity: lookaround', 'pattern' => '/(?=(a)(b))ab/', 'subjects' => ['ab']],
    ['source' => 'parity: lookaround', 'pattern' => '/(?!(a))(b)/', 'subjects' => ['b']],
    ['source' => 'parity: lookaround', 'pattern' => '/(?=(a)|(b))[ab]/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: lookaround', 'pattern' => '/(?=a(?<n>b)?)a/', 'subjects' => ['a', 'ab']],

    // Marks: (*MARK), and the verbs that set one when they carry a name.
    ['source' => 'parity: mark', 'pattern' => '/(*MARK:m)(a)/', 'subjects' => ['a']],
    ['source' => 'parity: mark', 'pattern' => '/(*:m)(a)/', 'subjects' => ['a']],
    ['source' => 'parity: mark', 'pattern' => '/(a)(*MARK:x)|(b)(*MARK:y)/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: mark', 'pattern' => '/(*MARK:m)(*MARK:n)(a)/', 'subjects' => ['a']],
    ['source' => 'parity: mark', 'pattern' => '/(?:(*MARK:x)a|(*MARK:y)b)+/', 'subjects' => ['ab', 'ba']],
    ['source' => 'parity: mark', 'pattern' => '/(*MARK:m)(?=(*MARK:n)a)a/', 'subjects' => ['a']],
    ['source' => 'parity: mark', 'pattern' => '/(*MARK:A)x|(a)/', 'subjects' => ['a', 'x']],
    ['source' => 'parity: mark', 'pattern' => '/(*THEN:t)(a)/', 'subjects' => ['a']],
    ['source' => 'parity: mark', 'pattern' => '/(*PRUNE:p)(a)/', 'subjects' => ['a']],
    ['source' => 'parity: mark', 'pattern' => '/(*COMMIT:c)(a)/', 'subjects' => ['a']],
    ['source' => 'parity: mark', 'pattern' => '/(*MARK:s)(a)(*SKIP:s)b/', 'subjects' => ['ab']],
    ['source' => 'parity: mark', 'pattern' => '/a(*F:m)|(b)/', 'subjects' => ['b']],

    // Accept: the groups left open hold what they read.
    ['source' => 'parity: accept', 'pattern' => '/(a)(*ACCEPT)(b)/', 'subjects' => ['a']],
    ['source' => 'parity: accept', 'pattern' => '/(a)(*ACCEPT:m)b/', 'subjects' => ['a']],
    ['source' => 'parity: accept', 'pattern' => '/(?:(a)(*ACCEPT)|b)(c)/', 'subjects' => ['a', 'bc']],
    ['source' => 'parity: accept', 'pattern' => '/(a(?:b(*ACCEPT)c)?)d/', 'subjects' => ['ab', 'ad']],
    ['source' => 'parity: accept', 'pattern' => '/(?=(a)(*ACCEPT)b)a/', 'subjects' => ['a']],
    ['source' => 'parity: accept', 'pattern' => '/(x(*ACCEPT)y)?z(?1)/', 'subjects' => ['zx', 'xzx']],

    // Recursion and subroutine calls: the groups a call sets are restored after it.
    ['source' => 'parity: recursion', 'pattern' => '/\((?:[^()]|(?R))*\)/', 'subjects' => ['()', '(a(b))']],
    ['source' => 'parity: recursion', 'pattern' => '/(\((?:[^()]|(?1))*\))/', 'subjects' => ['()', '(a(b))']],
    ['source' => 'parity: recursion', 'pattern' => '/(?<p>\((?:[^()]|(?&p))*\))/', 'subjects' => ['(())']],
    ['source' => 'parity: recursion', 'pattern' => '/(a)(?1)/', 'subjects' => ['aa']],
    ['source' => 'parity: recursion', 'pattern' => '/(?1)(a)/', 'subjects' => ['aa']],
    ['source' => 'parity: recursion', 'pattern' => '/(a)(?-1)/', 'subjects' => ['aa']],
    ['source' => 'parity: recursion', 'pattern' => '/(?+1)(a)/', 'subjects' => ['aa']],
    ['source' => 'parity: recursion', 'pattern' => '/(?P<n>a)(?P>n)/', 'subjects' => ['aa']],
    ['source' => 'parity: recursion', 'pattern' => '/(a|b(?1))/', 'subjects' => ['a', 'bba']],
    ['source' => 'parity: recursion', 'pattern' => '/(z(?1)?)/', 'subjects' => ['z', 'zz']],
    ['source' => 'parity: recursion', 'pattern' => '/(a(b)?)(?1)/', 'subjects' => ['aa', 'aab', 'aba']],
    ['source' => 'parity: recursion', 'pattern' => '/a(?R)?/', 'subjects' => ['a', 'aa']],

    // Conditionals.
    ['source' => 'parity: conditional', 'pattern' => '/(a)?(?(1)b|c)/', 'subjects' => ['ab', 'c']],
    ['source' => 'parity: conditional', 'pattern' => '/(?<n>a)?(?(<n>)b|c)/', 'subjects' => ['ab', 'c']],
    ['source' => 'parity: conditional', 'pattern' => "/(?<n>a)?(?('n')b|c)/", 'subjects' => ['ab', 'c']],
    ['source' => 'parity: conditional', 'pattern' => '/(?(?=a)(a)|(b))/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: conditional', 'pattern' => '/(?(?!a)(b)|(a))/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: conditional', 'pattern' => '/(?(?<=a)(b)|(c))/', 'subjects' => ['ab', 'c']],
    ['source' => 'parity: conditional', 'pattern' => '/(a)?(?(1)(b))/', 'subjects' => ['', 'ab']],
    ['source' => 'parity: conditional', 'pattern' => '/(?(R)a|(b))/', 'subjects' => ['b']],
    ['source' => 'parity: conditional', 'pattern' => '/(?(1)a|b)(x)?/', 'subjects' => ['b', 'bx']],
    ['source' => 'parity: conditional', 'pattern' => '/(?(VERSION>=10.0)(a)|(b))/', 'subjects' => ['a']],

    // DEFINE and zero repeats: groups that are never set.
    ['source' => 'parity: define', 'pattern' => '/(?(DEFINE)(?<d>\d+))(?&d)-(?&d)/', 'subjects' => ['1-2']],
    ['source' => 'parity: define', 'pattern' => '/(?(DEFINE)(a)(b))(?1)(?2)(c)/', 'subjects' => ['abc']],
    ['source' => 'parity: define', 'pattern' => '/(a)(?(DEFINE)(b))(c)/', 'subjects' => ['ac']],
    ['source' => 'parity: zero repeat', 'pattern' => '/(a){0}(b)/', 'subjects' => ['b']],
    ['source' => 'parity: zero repeat', 'pattern' => '/(a){0,0}b/', 'subjects' => ['b']],
    ['source' => 'parity: zero repeat', 'pattern' => '/(a){0}+b/', 'subjects' => ['b']],
    ['source' => 'parity: zero repeat', 'pattern' => '/(a){0}(?1)/', 'subjects' => ['a']],
    ['source' => 'parity: zero repeat', 'pattern' => '/(?:(a)){0}b(c)?/', 'subjects' => ['b', 'bc']],

    // Delimiters and modifiers.
    ['source' => 'parity: delimiters', 'pattern' => '{(a)}', 'subjects' => ['a']],
    ['source' => 'parity: delimiters', 'pattern' => '((a))', 'subjects' => ['a']],
    ['source' => 'parity: delimiters', 'pattern' => '[(a)]i', 'subjects' => ['A']],
    ['source' => 'parity: delimiters', 'pattern' => '<(a)>', 'subjects' => ['a']],
    ['source' => 'parity: delimiters', 'pattern' => '~(a)~', 'subjects' => ['a']],
    ['source' => 'parity: delimiters', 'pattern' => '%(a)%', 'subjects' => ['a']],
    ['source' => 'parity: delimiters', 'pattern' => '!(a)!', 'subjects' => ['a']],
    ['source' => 'parity: modifiers', 'pattern' => '/(a)/A', 'subjects' => ['ab']],
    ['source' => 'parity: modifiers', 'pattern' => '/^(a)$/D', 'subjects' => ['a']],
    ['source' => 'parity: modifiers', 'pattern' => '/(.)/s', 'subjects' => ["\n"]],
    ['source' => 'parity: modifiers', 'pattern' => '/(a)/S', 'subjects' => ['a']],
    ['source' => 'parity: modifiers', 'pattern' => '/(a)/X', 'subjects' => ['a']],
    ['source' => 'parity: modifiers', 'pattern' => '/(*LIMIT_MATCH=1000)(a)/', 'subjects' => ['a']],
    ['source' => 'parity: modifiers', 'pattern' => '/(*NOTEMPTY)(a)?b?/', 'subjects' => ['a', 'b']],

    // Values: digits, falsy text, letter cases, bytes and code points.
    ['source' => 'parity: values', 'pattern' => '/(\d+)/', 'subjects' => ['0', '123']],
    ['source' => 'parity: values', 'pattern' => '/(\d*)/', 'subjects' => ['', '7']],
    ['source' => 'parity: values', 'pattern' => '/(-?\d+)\.(\d+)/', 'subjects' => ['1.5', '-0.0']],
    ['source' => 'parity: values', 'pattern' => '/(.+)/', 'subjects' => ['0']],
    ['source' => 'parity: values', 'pattern' => '/([0-9])/', 'subjects' => ['0']],
    ['source' => 'parity: values', 'pattern' => '/(\w+)/', 'subjects' => ['0', 'abc']],
    ['source' => 'parity: values', 'pattern' => '/([a-z]+)/', 'subjects' => ['abc']],
    ['source' => 'parity: values', 'pattern' => '/([A-Z]+)/', 'subjects' => ['ABC']],
    ['source' => 'parity: values', 'pattern' => '/(\s+)/', 'subjects' => [' ', "\t\n"]],
    ['source' => 'parity: values', 'pattern' => '/(\X)/u', 'subjects' => ["e\u{301}"]],
    ['source' => 'parity: values', 'pattern' => '/(.)/u', 'subjects' => ['é']],
    ['source' => 'parity: values', 'pattern' => '/(\p{Lu})/u', 'subjects' => ['É']],
    ['source' => 'parity: values', 'pattern' => '/(\R)/', 'subjects' => ["\r\n", "\n"]],
    ['source' => 'parity: values', 'pattern' => '/(\xFF)/', 'subjects' => ["\xFF"]],
    ['source' => 'parity: values', 'pattern' => '/(\x{FF})/u', 'subjects' => ['ÿ']],
    ['source' => 'parity: values', 'pattern' => '/(*UCP)(\w)/u', 'subjects' => ['é']],
    ['source' => 'parity: values', 'pattern' => '/(a)\z|(b)/', 'subjects' => ['a', 'b']],

    // Facts: digits and non-falsy values. U+0663 ARABIC-INDIC DIGIT THREE is a
    // digit under UCP: preg_match('/(\d+)/u', "\u{663}") -> 1, ctype_digit() false;
    // (*UTF) alone keeps \d ASCII: preg_match('/(*UTF)(\d+)/', "\u{663}") -> 0;
    // a digit run can be falsy: preg_match('/(\d)/', '0') -> ["0","0"] (PHP 8.4.26, PCRE2 10.49).
    ['source' => 'parity: values', 'pattern' => '/(\d+)/u', 'subjects' => ['0', "\u{663}"]],
    ['source' => 'parity: values', 'pattern' => '/(*UTF)(*UCP)(\d+)/', 'subjects' => ["\u{663}"]],
    ['source' => 'parity: values', 'pattern' => '/(*UCP)(\d+)/', 'subjects' => ['0', '9']],
    ['source' => 'parity: values', 'pattern' => '/(*UTF)(\d+)/', 'subjects' => ['12']],
    ['source' => 'parity: values', 'pattern' => '/(\d+)/i', 'subjects' => ['12']],
    ['source' => 'parity: values', 'pattern' => '/([0-9]+)/u', 'subjects' => ['09']],
    ['source' => 'parity: values', 'pattern' => '/([[:digit:]]+)/u', 'subjects' => ["\u{663}"]],
    ['source' => 'parity: values', 'pattern' => '/(\p{Nd}+)/u', 'subjects' => ["\u{663}"]],
    ['source' => 'parity: values', 'pattern' => '/([\x30-\x39]+)/', 'subjects' => ['09']],
    ['source' => 'parity: values', 'pattern' => '/(\d\d)/', 'subjects' => ['00']],
    ['source' => 'parity: values', 'pattern' => '/(\d)/', 'subjects' => ['0']],
    ['source' => 'parity: values', 'pattern' => '/(0)/', 'subjects' => ['0']],
    ['source' => 'parity: values', 'pattern' => '/(a|0b)/', 'subjects' => ['a', '0b']],
    ['source' => 'parity: values', 'pattern' => '/(a|0)/', 'subjects' => ['a', '0']],
    ['source' => 'parity: values', 'pattern' => '/(a?)/', 'subjects' => ['', 'a']],
    ['source' => 'parity: values', 'pattern' => '/(a?)(\1)/', 'subjects' => ['', 'aa']],
    ['source' => 'parity: values', 'pattern' => '/(0(*ACCEPT)1)/', 'subjects' => ['01']],
    ['source' => 'parity: values', 'pattern' => '/([1-9])/', 'subjects' => ['1']],
    ['source' => 'parity: values', 'pattern' => '/(\d+)?/', 'subjects' => ['', '0']],
    ['source' => 'parity: values', 'pattern' => '/(ab)?/', 'subjects' => ['', 'ab']],
    ['source' => 'parity: values', 'pattern' => '/(1a)/', 'subjects' => ['1a']],
    ['source' => 'parity: values', 'pattern' => '/(.)/', 'subjects' => ['0']],
    ['source' => 'parity: values', 'pattern' => '/(é)/u', 'subjects' => ['é']],
    ['source' => 'parity: values', 'pattern' => '/(ab)/i', 'subjects' => ['AB']],
    ['source' => 'parity: values', 'pattern' => '/(\d+)\.(\d+)/', 'subjects' => ['1.5']],
    ['source' => 'parity: values', 'pattern' => '/(\d\d+)/', 'subjects' => ['00']],

    // Cases: an alternation reachable from the root, split per branch. An
    // option set in one alternative stays on in the next ones:
    // preg_match('/a(?i)b|(c)/', 'C') -> ["C","C"]. Sixteen cases split, seventeen
    // do not; a second alternation with groups, a repeat, a capturing group or a
    // lookahead around the alternation prevents the split.
    ['source' => 'parity: cases', 'pattern' => '/(a)|(b)/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: cases', 'pattern' => '/^(?:(\d+)|([a-z]+))$/', 'subjects' => ['0', '42', 'ab']],
    ['source' => 'parity: cases', 'pattern' => '/x(?:(a)|(b))/', 'subjects' => ['xa', 'xb']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b))?/', 'subjects' => ['', 'a', 'b']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b))?c/', 'subjects' => ['c', 'ac', 'bc']],
    ['source' => 'parity: cases', 'pattern' => '/a(?i)b|(c)/', 'subjects' => ['ab', 'aB', 'c', 'C']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b))(?:c|d)/', 'subjects' => ['ac', 'bd']],
    ['source' => 'parity: cases', 'pattern' => '/(x)(?:(a)|(b))/', 'subjects' => ['xa', 'xb']],
    ['source' => 'parity: cases', 'pattern' => '/(a)(b)?|(c)/', 'subjects' => ['a', 'ab', 'c']],
    ['source' => 'parity: cases', 'pattern' => '/(a)|(b)|(c)|(d)|(e)|(f)|(g)|(h)|(i)|(j)|(k)|(l)|(m)|(n)|(o)|(p)/', 'subjects' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b)|(c)|(d)|(e)|(f)|(g)|(h)|(i)|(j)|(k)|(l)|(m)|(n)|(o))?/', 'subjects' => ['', 'a', 'o']],
    ['source' => 'parity: cases', 'pattern' => '/(a)|(b)|(c)|(d)|(e)|(f)|(g)|(h)|(i)|(j)|(k)|(l)|(m)|(n)|(o)|(p)|(q)/', 'subjects' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b)|(c)|(d)|(e)|(f)|(g)|(h)|(i)|(j)|(k)|(l)|(m)|(n)|(o)|(p))?/', 'subjects' => ['', 'a', 'p']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b))(?:(c)|(d))/', 'subjects' => ['ac', 'ad', 'bc', 'bd']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b))*/', 'subjects' => ['', 'ab', 'ba']],
    ['source' => 'parity: cases', 'pattern' => '/((a)|(b))/', 'subjects' => ['a', 'b']],
    ['source' => 'parity: cases', 'pattern' => '/(?=(a)|(b))\w/', 'subjects' => ['a', 'b']],
    // Two groups in the taken branch; a branch reset that captures nothing does not
    // prevent the split; a group a case never sets proves no fact; a call runs the
    // \K or the mark of a branch the case leaves out: preg_match('/(?:(a)|(b\K))(?2)/',
    // 'ab') -> ["","a"], preg_match('/(?:(a)|(b(*MARK:m)))(?2)/', 'ab') ->
    // {"0":"ab","1":"a","MARK":"m"}.
    ['source' => 'parity: cases', 'pattern' => '/(a)(b)|(c)/', 'subjects' => ['ab', 'c']],
    ['source' => 'parity: cases', 'pattern' => '/(?|a|b)(?:(c)|(d))/', 'subjects' => ['ac', 'bd']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(\d\d)|(a))/', 'subjects' => ['00', 'a']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b\K))(?2)/', 'subjects' => ['ab', 'bb']],
    ['source' => 'parity: cases', 'pattern' => '/(?:(a)|(b(*MARK:m)))(?2)/', 'subjects' => ['ab', 'bb']],
];
