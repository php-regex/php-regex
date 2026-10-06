<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipArraySubject
{
    public function run(array $subject): array
    {
        return preg_replace('/foo/', 'X', $subject);
    }
}

?>
