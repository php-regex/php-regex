<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\FixturePhp81;

final class SkipNoAutoCaptureModifier
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/abc/n', $subject);
    }
}

?>
