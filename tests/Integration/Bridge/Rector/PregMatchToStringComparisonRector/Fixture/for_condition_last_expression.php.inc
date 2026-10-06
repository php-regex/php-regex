<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ForConditionLastExpression
{
    public function run(string $subject): bool
    {
        for ($runs = 0; preg_match('/x/', $subject), preg_match('/foo/', $subject); ++$runs) {
            return true;
        }

        return false;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ForConditionLastExpression
{
    public function run(string $subject): bool
    {
        for ($runs = 0; preg_match('/x/', $subject), \str_contains($subject, 'foo'); ++$runs) {
            return true;
        }

        return false;
    }
}

?>
