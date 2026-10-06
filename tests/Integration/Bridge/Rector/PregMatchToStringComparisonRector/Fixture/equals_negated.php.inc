<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsNegated
{
    public function run(string $subject): bool
    {
        return preg_match('/^foo\z/', $subject) === 0;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsNegated
{
    public function run(string $subject): bool
    {
        return 'foo' !== $subject;
    }
}

?>
