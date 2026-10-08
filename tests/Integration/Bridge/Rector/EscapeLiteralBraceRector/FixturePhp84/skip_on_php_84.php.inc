<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\FixturePhp84;

final class SkipOnPhp84
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a{,3}/', $subject);
    }
}

?>
