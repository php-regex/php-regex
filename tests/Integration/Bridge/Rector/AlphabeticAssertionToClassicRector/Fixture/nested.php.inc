<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class Nested
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(*nla:(*plb:x)y)z/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class Nested
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(?!(?<=x)y)z/', $subject);
    }
}

?>
