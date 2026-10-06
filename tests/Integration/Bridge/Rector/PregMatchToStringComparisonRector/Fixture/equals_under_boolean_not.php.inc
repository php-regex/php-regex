<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsUnderBooleanNot
{
    public function run(string $subject): bool
    {
        return !preg_match('/^foo\z/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsUnderBooleanNot
{
    public function run(string $subject): bool
    {
        return 'foo' !== $subject;
    }
}

?>
