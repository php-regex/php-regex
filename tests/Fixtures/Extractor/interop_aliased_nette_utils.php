<?php

namespace App\Interop;

use Nette\Utils\Strings as S;

final class AliasedNetteUtils
{
    public function run(string $subject): void
    {
        S::match($subject, '/aliased-nette/');
    }
}
