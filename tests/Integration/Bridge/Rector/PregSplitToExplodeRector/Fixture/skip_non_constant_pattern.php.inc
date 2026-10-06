<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipNonConstantPattern
{
    public function run(string $subject, string $pattern): array
    {
        return preg_split($pattern, $subject);
    }
}

?>
