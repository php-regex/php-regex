<?php

namespace App\Interop;

use Composer\{Pcre\Preg as P};
use Illuminate\{Support\Str as S};

final class AliasedSplitGroupUse
{
    public function run(string $subject): void
    {
        P::match('/split-composer/', $subject);
        S::match('/split-laravel/', $subject);
    }
}
