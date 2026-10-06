<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class OneIdenticalTo
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class OneIdenticalTo
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'foo');
    }
}

?>
