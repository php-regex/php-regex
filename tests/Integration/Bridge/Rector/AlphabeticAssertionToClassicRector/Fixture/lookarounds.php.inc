<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class Lookarounds
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(*pla:foo)\w+(*nlb:bar)/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class Lookarounds
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(?=foo)\w+(?<!bar)/', $subject);
    }
}

?>
