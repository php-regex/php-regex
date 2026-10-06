<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsMethodCallSubject
{
    public function run(string $subject): int
    {
        return preg_match('/^foo\z/', $this->identity($subject)) ? 1 : 2;
    }

    private function identity(string $value): string
    {
        return $value;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsMethodCallSubject
{
    public function run(string $subject): int
    {
        return 'foo' === $this->identity($subject) ? 1 : 2;
    }

    private function identity(string $value): string
    {
        return $value;
    }
}

?>
