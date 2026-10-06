<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipDollarGroupReference
{
    public function run(string $subject): string
    {
        return preg_replace('/(foo)/', '[$1]', $subject);
    }
}

?>
