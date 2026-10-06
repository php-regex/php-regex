<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BooleanAnd
{
    public function run(string $subject): bool
    {
        return '' !== $subject && preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class BooleanAnd
{
    public function run(string $subject): bool
    {
        return '' !== $subject && \str_contains($subject, 'foo');
    }
}

?>
