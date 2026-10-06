<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipPhpdocStringSubjectForInArray
{
    /**
     * @param string $subject
     */
    public function run($subject): bool
    {
        return (bool) preg_match('/^(?:GET|POST)\z/', $subject);
    }
}

?>
