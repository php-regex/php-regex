<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\FixturePhp80;

final class OpenCountPast65535
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a{65536|b{1,99999/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\FixturePhp80;

final class OpenCountPast65535
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a\{65536|b\{1,99999/', $subject);
    }
}

?>
