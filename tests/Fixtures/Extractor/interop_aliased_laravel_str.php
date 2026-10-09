<?php

namespace App\Interop;

use Illuminate\Support\Str as S;

final class AliasedLaravelStr
{
    public function run(string $subject): void
    {
        S::match('/aliased-laravel/', $subject);
    }
}
