<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

// A string function would need DEL (0x7F) in its literal, which the printer
// writes as an invisible raw byte: the call is left as it is.
final class SkipDeleteByteLiteral
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/\x7f/', $subject);
    }
}

?>
