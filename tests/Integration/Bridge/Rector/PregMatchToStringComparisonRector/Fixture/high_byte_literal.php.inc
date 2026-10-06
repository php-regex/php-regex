<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class HighByteLiteral
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/\xff/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class HighByteLiteral
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, "\xff");
    }
}

?>
