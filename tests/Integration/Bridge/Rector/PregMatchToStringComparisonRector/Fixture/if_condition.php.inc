<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IfCondition
{
    public function run(string $subject): bool
    {
        if (preg_match('/foo/', $subject)) {
            return true;
        }

        return false;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IfCondition
{
    public function run(string $subject): bool
    {
        if (\str_contains($subject, 'foo')) {
            return true;
        }

        return false;
    }
}

?>
