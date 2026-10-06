<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class SkipVariableReplacement
{
    public function run(string $subject, string $replacement): string
    {
        return preg_replace('/foo/', $replacement, $subject);
    }
}

?>
