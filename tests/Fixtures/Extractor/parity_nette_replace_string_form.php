<?php

namespace App\Parity;

use Nette\Utils\Strings;

final class NetteReplaceStringForm
{
    public function run(string $subject): void
    {
        Strings::replace($subject, '/nette-string/', 'y');
    }
}
