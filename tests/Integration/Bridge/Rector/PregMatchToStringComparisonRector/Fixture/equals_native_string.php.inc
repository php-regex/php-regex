<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsNativeString
{
    public function run(string $subject): bool
    {
        if (preg_match('/^foo\z/', $subject)) {
            return true;
        }

        return false;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsNativeString
{
    public function run(string $subject): bool
    {
        if ('foo' === $subject) {
            return true;
        }

        return false;
    }
}

?>
