<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class DollarTakesAFinalNewline
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/^foo$/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class DollarTakesAFinalNewline
{
    public function run(string $subject): bool
    {
        return \in_array($subject, ['foo', "foo\n"], true);
    }
}

?>
