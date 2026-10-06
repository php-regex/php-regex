<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class TernaryCondition
{
    public function run(string $subject): string
    {
        return preg_match('/foo/', $subject) ? 'yes' : 'no';
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class TernaryCondition
{
    public function run(string $subject): string
    {
        return \str_contains($subject, 'foo') ? 'yes' : 'no';
    }
}

?>
