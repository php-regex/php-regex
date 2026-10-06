<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class TabLiteral
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/\t/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class TabLiteral
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, "\t");
    }
}

?>
