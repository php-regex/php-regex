<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class PhpdocStringSubjectForAStringFunction
{
    /**
     * @param string $subject
     */
    public function run($subject): bool
    {
        return (bool) preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class PhpdocStringSubjectForAStringFunction
{
    /**
     * @param string $subject
     */
    public function run($subject): bool
    {
        return \str_contains($subject, 'foo');
    }
}

?>
