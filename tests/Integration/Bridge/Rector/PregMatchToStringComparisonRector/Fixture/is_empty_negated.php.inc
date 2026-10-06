<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IsEmptyNegated
{
    public function run(string $subject): bool
    {
        return !preg_match('/^\z/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IsEmptyNegated
{
    public function run(string $subject): bool
    {
        return '' !== $subject;
    }
}

?>
