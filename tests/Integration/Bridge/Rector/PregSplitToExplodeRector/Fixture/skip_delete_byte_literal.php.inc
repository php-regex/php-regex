<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

// DEL (0x7F) would be printed as an invisible raw byte: left as it is.
final class SkipDeleteByteLiteral
{
    public function run(string $subject): array|false
    {
        return preg_split('/\x7f/', $subject);
    }
}

?>
