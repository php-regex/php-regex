<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipNonConstantPattern
{
    public function run(string $subject, string $pattern): string
    {
        return preg_replace($pattern, 'X', $subject);
    }
}

?>
