<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

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

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class BraceWithoutMinimum
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a\{,3}/', $subject);
    }
}

?>
