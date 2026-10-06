<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipNamedArguments
{
    public function run(string $subject): string
    {
        return preg_replace(pattern: '/foo/', replacement: 'X', subject: $subject);
    }
}

?>
