<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

function str_contains(string $haystack, string $needle): bool
{
    throw new \LogicException('The global str_contains() is meant.');
}

final class NamespacedFunctionOfTheSameName
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/foo/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

function str_contains(string $haystack, string $needle): bool
{
    throw new \LogicException('The global str_contains() is meant.');
}

final class NamespacedFunctionOfTheSameName
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'foo');
    }
}

?>
