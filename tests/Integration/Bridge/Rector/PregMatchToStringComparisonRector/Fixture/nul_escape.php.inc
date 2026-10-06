<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class NulEscape
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/a\x00b/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class NulEscape
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, "a\x00b");
    }
}

?>
