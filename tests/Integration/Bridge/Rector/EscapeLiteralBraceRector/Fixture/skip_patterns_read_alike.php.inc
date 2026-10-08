<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class SkipPatternsReadAlike
{
    public function run(string $subject, string $tail): void
    {
        preg_match('/a{0,3}/', $subject);
        preg_match('/a\{,3}/', $subject);
        preg_match('/[{,3}]/', $subject);
        preg_match('/\Q{,3}\E/', $subject);
        preg_match('/a{,3}' . $tail . '/', $subject);
    }
}

?>
