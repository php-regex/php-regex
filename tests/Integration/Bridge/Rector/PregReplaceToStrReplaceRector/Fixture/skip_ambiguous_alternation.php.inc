<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipAmbiguousAlternation
{
    public function run(string $subject): ?string
    {
        return preg_replace('/(?:a|a){20}b/', 'X', $subject);
    }
}

?>
