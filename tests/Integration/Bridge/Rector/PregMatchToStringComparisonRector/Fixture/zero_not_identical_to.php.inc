<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ZeroNotIdenticalTo
{
    public function run(string $subject): bool
    {
        return 0 !== preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ZeroNotIdenticalTo
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'foo');
    }
}

?>
