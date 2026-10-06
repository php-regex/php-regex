<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IsEmpty
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/^\z/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IsEmpty
{
    public function run(string $subject): bool
    {
        return '' === $subject;
    }
}

?>
