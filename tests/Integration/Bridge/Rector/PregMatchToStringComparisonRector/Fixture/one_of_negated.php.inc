<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class OneOfNegated
{
    public function run(string $subject): bool
    {
        return !preg_match('/^(?:GET|POST)\z/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class OneOfNegated
{
    public function run(string $subject): bool
    {
        return !\in_array($subject, ['GET', 'POST'], true);
    }
}

?>
