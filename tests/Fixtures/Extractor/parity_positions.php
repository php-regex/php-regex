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
        Strings::replace($subject, ['/key/' => 'x']);
        Strings::match($subject, '/second/');
    }
}
