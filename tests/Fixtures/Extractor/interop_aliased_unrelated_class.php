<?php

namespace App\Interop;

use Illuminate\Support\Collection as C;

/**
 * Same namespace as a wrapper, but not a wrapper: the file is read, and the
 * call is left alone.
 */
final class AliasedUnrelatedClass
{
    public function run(string $subject): void
    {
        C::match('/not-a-pattern/', $subject);
    }
}
