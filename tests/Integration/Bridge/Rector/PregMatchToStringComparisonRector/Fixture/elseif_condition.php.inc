<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ElseifCondition
{
    public function run(string $subject): bool
    {
        if ('' === $subject) {
            return false;
        } elseif (preg_match('/^foo/', $subject)) {
            return true;
        }

        return false;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ElseifCondition
{
    public function run(string $subject): bool
    {
        if ('' === $subject) {
            return false;
        } elseif (\str_starts_with($subject, 'foo')) {
            return true;
        }

        return false;
    }
}

?>
