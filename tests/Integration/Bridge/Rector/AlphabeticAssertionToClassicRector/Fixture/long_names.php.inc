<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class LongNames
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(*positive_lookbehind:a)b(*negative_lookahead:c)/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class LongNames
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(?<=a)b(?!c)/', $subject);
    }
}

?>
