<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class DollarInLiteral
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/a\$b/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class DollarInLiteral
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'a$b');
    }
}

?>
