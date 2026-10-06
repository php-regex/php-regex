<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BoolCast
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BoolCast
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'foo');
    }
}

?>
