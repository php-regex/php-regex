<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class EmptyReplacement
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/', '', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class EmptyReplacement
{
    public function run(string $subject): string
    {
        return \str_replace('foo', '', $subject);
    }
}

?>
