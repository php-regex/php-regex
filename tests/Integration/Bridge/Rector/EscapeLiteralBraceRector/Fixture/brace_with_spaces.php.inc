<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class BraceWithSpaces
{
    public function run(string $subject): string
    {
        return preg_replace('/x{ 2 }/', 'y', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class BraceWithSpaces
{
    public function run(string $subject): string
    {
        return preg_replace('/x\{ 2 }/', 'y', $subject);
    }
}

?>
