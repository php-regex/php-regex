<?php

namespace App\Parity;

use Nette\Utils\Strings;

final class SpreadBeforePattern
{
    /**
     * @param array<int, mixed> $arguments
     */
    public function run(string $subject, array $arguments): void
    {
        preg_match(...$arguments);
        Strings::match($subject, ...$arguments);
        preg_match('/before-spread/', ...$arguments);
    }
}
