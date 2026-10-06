<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipNullableSubject
{
    public function run(?string $subject): ?string
    {
        return preg_replace('/foo/', 'X', $subject);
    }
}

?>
