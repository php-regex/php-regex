<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\FixturePhp81;

final class NulEscape
{
    public function run(string $subject): string
    {
        return preg_replace('/a\x00b/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\FixturePhp81;

final class NulEscape
{
    public function run(string $subject): string
    {
        return \str_replace("a\x00b", 'X', $subject);
    }
}

?>
