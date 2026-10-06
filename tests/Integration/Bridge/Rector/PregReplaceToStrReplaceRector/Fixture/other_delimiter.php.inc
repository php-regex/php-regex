<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class OtherDelimiter
{
    public function run(string $subject): string
    {
        return preg_replace('#a/b#', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class OtherDelimiter
{
    public function run(string $subject): string
    {
        return \str_replace('a/b', 'X', $subject);
    }
}

?>
