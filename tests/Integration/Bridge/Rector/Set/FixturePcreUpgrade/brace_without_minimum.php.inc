<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\Set\FixturePcreUpgrade;

final class BraceWithoutMinimum
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a{,3}/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\Set\FixturePcreUpgrade;

final class BraceWithoutMinimum
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a\{,3}/', $subject);
    }
}

?>
