<?php

namespace App\Interop;

use Spatie\Regex\Regex as R;

final class AliasedSpatieRegex
{
    public function run(string $subject): void
    {
        R::match('/aliased-spatie/', $subject);
    }
}
