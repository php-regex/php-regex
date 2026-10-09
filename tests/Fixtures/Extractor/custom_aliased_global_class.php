<?php

namespace App\Http;

use Text as X;

final class AliasedGlobalClass
{
    public function run(string $subject): void
    {
        X::m('/aliased-global/', $subject);
    }
}
