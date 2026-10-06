<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class DoubleQuotedReplacement
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/', "bar\n", $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class DoubleQuotedReplacement
{
    public function run(string $subject): string
    {
        return \str_replace('foo', "bar\n", $subject);
    }
}

?>
