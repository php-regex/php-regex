<?php

namespace App\Http;

use App\Support\Re as R;

final class AliasedCustomClass
{
    public function run(string $subject): void
    {
        R::m('/aliased-custom/', $subject);
    }
}
