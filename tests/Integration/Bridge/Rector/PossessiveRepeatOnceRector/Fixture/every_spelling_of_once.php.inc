<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class EverySpellingOfOnce
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(?:a|ab){1,1}+c|(?<n>x|xy){01}+z/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class EverySpellingOfOnce
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(?:a|ab){1,1}c|(?<n>x|xy){01}z/', $subject);
    }
}

?>
