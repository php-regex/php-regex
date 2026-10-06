<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ExtendedMode
{
    public function run(string $subject): string
    {
        return preg_replace('/f o o/x', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ExtendedMode
{
    public function run(string $subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
