<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class QuoteInLiteral
{
    public function run(string $subject): bool
    {
        return (bool) preg_match("/it's/", $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class QuoteInLiteral
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'it\'s');
    }
}

?>
