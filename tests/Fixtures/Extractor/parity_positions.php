<?php

namespace App\Parity;

use Nette\Utils\Strings;

final class Positions
{
    public function run(string $subject, string $y): void
    {
        preg_replace_callback_array(['/a/' => function ($m) { return 'x'; }, '/b/' => fn() => 1], $subject);
        preg_replace(["{$y}", '/q/'], '', $subject);
        preg_match(  (  '/parenthesized/'  ), $subject);
        preg_match(subject: $subject, pattern: '/named/');
        preg_match(<<<'RE'
            /heredoc/
            RE, $subject);
        preg_match('/concat-' . 'enated/', $subject);
        preg_match(('/paren-concat/') . 'i', $subject);
        preg_match(
            (
                '/paren-concat-multiline/'
            ) . 'i',
            $subject,
        );
        preg_replace([('/key-paren-concat/') . 'i'], '', $subject);
        preg_match(((('/nested-paren-concat/')) . 'i'), $subject);
        Strings::replace($subject, ['/key/' => 'x']);
        Strings::match($subject, '/second/');
    }
}
