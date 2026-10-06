<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class LogicalAnd
{
    public function run(string $subject): bool
    {
        return '' !== $subject and preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class LogicalAnd
{
    public function run(string $subject): bool
    {
        return '' !== $subject and \str_contains($subject, 'foo');
    }
}

?>
