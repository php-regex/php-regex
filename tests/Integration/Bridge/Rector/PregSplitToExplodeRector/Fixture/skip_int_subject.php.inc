<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipIntSubject
{
    public function run(int $subject): array
    {
        return preg_split('/1/', $subject);
    }
}

?>
