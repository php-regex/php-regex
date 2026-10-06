<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IdenticalToZero
{
    public function run(string $subject): bool
    {
        return preg_match('/foo/', $subject) === 0;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class IdenticalToZero
{
    public function run(string $subject): bool
    {
        return !\str_contains($subject, 'foo');
    }
}

?>
