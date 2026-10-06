<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipLoneDollar
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/', 'cost: $', $subject);
    }
}

?>
