<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class Condition
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(?(*pla:a)ab|cd)/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class Condition
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(?(?=a)ab|cd)/', $subject);
    }
}

?>
