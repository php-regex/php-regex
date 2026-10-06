<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class LogicalXor
{
    public function run(string $subject): bool
    {
        return preg_match('/foo/', $subject) xor '' === $subject;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class LogicalXor
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'foo') xor '' === $subject;
    }
}

?>
