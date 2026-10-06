<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsPropertyFetchSubject
{
    private string $value = '';

    public function run(string $subject): bool
    {
        $this->value = $subject;

        return preg_match('/^foo\z/', $this->value) === 0;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class EqualsPropertyFetchSubject
{
    private string $value = '';

    public function run(string $subject): bool
    {
        $this->value = $subject;

        return 'foo' !== $this->value;
    }
}

?>
