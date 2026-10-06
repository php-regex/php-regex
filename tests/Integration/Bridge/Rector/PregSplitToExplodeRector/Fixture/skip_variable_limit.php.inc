<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipVariableLimit
{
    public function run(string $subject, int $limit): array
    {
        return preg_split('/,/', $subject, $limit);
    }
}

?>
