<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class WhileCondition
{
    public function run(string $subject): bool
    {
        while (preg_match('/foo\z/', $subject)) {
            return true;
        }

        return false;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class WhileCondition
{
    public function run(string $subject): bool
    {
        while (\str_ends_with($subject, 'foo')) {
            return true;
        }

        return false;
    }
}

?>
