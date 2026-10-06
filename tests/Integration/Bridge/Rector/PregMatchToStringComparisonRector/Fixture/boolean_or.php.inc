<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BooleanOr
{
    public function run(string $subject): bool
    {
        return '' === $subject || preg_match('/^foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BooleanOr
{
    public function run(string $subject): bool
    {
        return '' === $subject || \str_starts_with($subject, 'foo');
    }
}

?>
