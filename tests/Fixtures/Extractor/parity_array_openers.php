<?php

namespace App\Parity;

use JetBrains\PhpStorm\Pure;

final class ArrayOpeners
{
    public function run(string $subject, int $x, string $y): void
    {
        preg_replace_callback_array(['/attr-a/' => #[Pure] fn ($m) => 'x', '/attr-b/' => fn ($m) => 'y'], $subject);
        preg_replace_callback_array(['/real/' => match ($x) { 1 => fn () => "{$y}", '/not-a-key/' => fn () => 1 }], $subject);
    }
}
