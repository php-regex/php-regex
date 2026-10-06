<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ClassConstantPattern
{
    private const PATTERN = '/foo/';

    public function run(string $subject): bool
    {
        return (bool) preg_match(self::PATTERN, $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class ClassConstantPattern
{
    private const PATTERN = '/foo/';

    public function run(string $subject): bool
    {
        return \str_contains($subject, 'foo');
    }
}

?>
