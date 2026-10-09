<?php

namespace App\Interop;

use Illuminate\Support\Collection as C;
use Illuminate\Support\Str as S;

/**
 * The Str import opens the file; the call goes to another class of the same
 * namespace and is left alone.
 */
final class AliasedUnrelatedClass
{
    public function run(string $subject): string
    {
        C::match('/not-a-pattern/', $subject);

        return S::upper($subject);
    }
}
