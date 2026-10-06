<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class LogicalOr
{
    public function run(string $subject): bool
    {
        return '' === $subject or preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class LogicalOr
{
    public function run(string $subject): bool
    {
        return '' === $subject or \str_contains($subject, 'foo');
    }
}

?>
