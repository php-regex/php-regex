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

        // One of several patterns.
        $branches = '/state-branch-a/';
        if ($flag) {
            $branches = '/state-branch-b/';
        }
        preg_match($branches, $subject);

        // A static pattern around preg_quote() texts.
        preg_match('/^state-' . preg_quote($word, '/') . '$/', $subject);
        preg_match('/^state-' . preg_quote($word) . '$/', $subject);

        // A static pattern around another part built at run time.
        preg_match('/^state-' . $word . '$/', $subject);
        preg_match('/^state-' . 1.5 . '$/', $subject);

        // Nothing static.
        preg_match($given, $subject);
        preg_match($this->build(), $subject);
        preg_match(build_state(), $subject);
        preg_match($other->pattern, $subject);
        preg_match($map['state'], $subject);
        preg_match($flag ? '/state-then/' : '/state-else/', $subject);
        preg_match("/^state-{$word}$/", $subject);
        preg_match($word . 'state' . $word, $subject);
        preg_match(preg_quote($word) . '/state/', $subject);

        // An array literal: one argument per item, a key per pattern.
        preg_replace(['/state-item/', $given . '/item/', '/^state-item-' . $word . '/'], '', $subject);
        preg_replace_callback_array(['/state-key/' => 'trim', '/^state-key-' . preg_quote($word, '/') . '/' => 'trim'], $subject);
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
