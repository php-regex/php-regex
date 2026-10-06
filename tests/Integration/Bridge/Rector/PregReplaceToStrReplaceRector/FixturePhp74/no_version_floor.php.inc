<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\FixturePhp74;

final class NoVersionFloor
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\FixturePhp74;

final class NoVersionFloor
{
    public function run(string $subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
