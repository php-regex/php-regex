<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class HighByteLiteral
{
    public function run(string $subject): string
    {
        return preg_replace('/\xff/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class HighByteLiteral
{
    public function run(string $subject): string
    {
        return \str_replace("\xff", 'X', $subject);
    }
}

?>
