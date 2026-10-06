<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BooleanNot
{
    public function run(string $subject): bool
    {
        return !preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BooleanNot
{
    public function run(string $subject): bool
    {
        return !\str_contains($subject, 'foo');
    }
}

?>
