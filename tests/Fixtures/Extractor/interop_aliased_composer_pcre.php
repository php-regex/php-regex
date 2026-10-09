<?php

namespace App\Interop;

use Composer\Pcre\Preg as P;

final class AliasedComposerPcre
{
    public function run(string $subject): void
    {
        P::match('/aliased-composer/', $subject);
    }
}
