<?php

namespace App\Parity;

const NAMESPACED = '/state-namespace-const/';

enum StateKind: string
{
    case Word = '/state-enum-case/';
}

final class States
{
    private const RE = '/state-class-const/';
    private const PART = 'abc';
    private const MAX = 3;
    private const CLOSED = '/state-closure/';
    private const BRANCHING = \PHP_INT_SIZE === 8 ? '/state-const-wide/' : '/state-const-narrow/';
    private string $property = '/state-property/';

    public function run(string $subject, string $given, string $word, bool $flag, object $other, array $map): void
    {
        // One pattern.
        preg_match('/state-literal/', $subject);
        preg_match('/state-' . 'concat/', $subject);
        preg_match(NAMESPACED, $subject);
        preg_match(self::RE, $subject);
        preg_match(StateKind::Word->value, $subject);
        preg_match('/state-' . self::PART . '+/', $subject);
        preg_match($this->property, $subject);
        preg_match('/^state-a{' . self::MAX . '}$/', $subject);
        $once = '/state-variable/';
        preg_match($once, $subject);
        $part = 'abc';
        preg_match('/state-' . $part . '-operand/', $subject);
        preg_match("/state-{$part}-interpolated/", $subject);
        preg_match(sprintf('/state-%s-%d/', 'sprintf', 7), $subject);
        preg_match(implode('|', ['/state-implode', 'joined/']), $subject);
        $words = ['word' => '/state-item-by-key/', 'digit' => '/state-item-other/'];
        preg_match($words['word'], $subject);
        array_filter([$subject], static fn (string $item): bool => 1 === preg_match(self::CLOSED, $item));

        // One of several patterns.
        $branches = '/state-branch-a/';
        if ($flag) {
            $branches = '/state-branch-b/';
        }
        preg_match($branches, $subject);
        preg_match($flag ? '/state-then/' : '/state-else/', $subject);
        preg_match(match ($flag) { true => '/state-match-a/', false => '/state-match-b/' }, $subject);
        foreach (['/state-loop-value-a/', '/state-loop-value-b/'] as $looped) {
            preg_match($looped, $subject);
        }
        foreach (['/state-loop-key-a/' => 1, '/state-loop-key-b/' => 2] as $key => $weight) {
            preg_match($key, $subject);
        }
        preg_match($words[$given], $subject);
        preg_match(self::BRANCHING, $subject);
        preg_match('~^state' . \DIRECTORY_SEPARATOR . '~', $subject);

        // A static pattern around preg_quote() texts.
        preg_match('/^state-' . preg_quote($word, '/') . '$/', $subject);
        preg_match('/^state-' . preg_quote($word) . '$/', $subject);

        // A static pattern around another part built at run time.
        preg_match('/^state-' . $word . '$/', $subject);
        preg_match('/^state-' . 1.5 . '$/', $subject);
        preg_match("/^state-{$word}$/", $subject);

        // Nothing static.
        preg_match($given, $subject);
        preg_match($this->build(), $subject);
        preg_match(build_state(), $subject);
        preg_match($other->pattern, $subject);
        preg_match($map['state'], $subject);
        preg_match($word . 'state' . $word, $subject);
        preg_match(preg_quote($word) . '/state/', $subject);

        // An array literal: one argument per item, a key per pattern.
        preg_replace(['/state-item/', $given . '/item/', '/^state-item-' . $word . '/'], '', $subject);
        preg_replace_callback_array(['/state-key/' => 'trim', '/^state-key-' . preg_quote($word, '/') . '/' => 'trim'], $subject);

        // An array held in a variable: one argument per item too.
        $list = ['/state-list-a/', '/state-list-b/'];
        preg_replace($list, '', $subject);
    }

    private function build(): string
    {
        return '';
    }
}

$fileLevel = '/state-file-level/';
preg_match($fileLevel, $subject);

foreach (['/state-loop-a/', '/state-loop-b/'] as $each) {
    preg_match($each, $subject);
}

function captured(string $subject): \Closure
{
    $captured = '/state-captured/';

    return static fn (): int => preg_match($captured, $subject);
}
