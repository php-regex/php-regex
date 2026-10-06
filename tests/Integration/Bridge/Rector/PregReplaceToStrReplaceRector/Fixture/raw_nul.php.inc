<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class RawNul
{
    public function run(string $subject): string
    {
        return preg_replace("/a\0b/", 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class RawNul
{
    public function run(string $subject): string
    {
        return \str_replace("a\x00b", 'X', $subject);
    }
}

?>
