<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

// DEL (0x7F) would be printed as an invisible raw byte: left as it is.
final class SkipDeleteByteLiteral
{
    public function run(string $subject): string
    {
        return preg_replace('/a\x7fb/', 'X', $subject);
    }
}

?>
