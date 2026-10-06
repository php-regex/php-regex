<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipIntSubject
{
    public function run(int $subject): string
    {
        return preg_replace('/1/', 'X', $subject);
    }
}

?>
