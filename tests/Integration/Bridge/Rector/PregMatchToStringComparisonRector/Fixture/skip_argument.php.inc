<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipArgument
{
    public function run(string $subject): string
    {
        return var_export(preg_match('/foo/', $subject), true);
    }
}

?>
