<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class SubroutineCallRepeatedOnce
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(a|ab)(?1){1}+c/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class SubroutineCallRepeatedOnce
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/(a|ab)(?1){1}c/', $subject);
    }
}

?>
