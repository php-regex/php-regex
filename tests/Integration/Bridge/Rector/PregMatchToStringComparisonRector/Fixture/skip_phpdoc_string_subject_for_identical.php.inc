<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipPhpdocStringSubjectForIdentical
{
    /**
     * @param string $subject
     */
    public function run($subject): bool
    {
        return (bool) preg_match('/^foo\z/', $subject);
    }
}

?>
