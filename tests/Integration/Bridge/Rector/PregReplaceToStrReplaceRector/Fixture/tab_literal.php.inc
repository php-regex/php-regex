<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class TabLiteral
{
    public function run(string $subject): string
    {
        return preg_replace('/\t/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class TabLiteral
{
    public function run(string $subject): string
    {
        return \str_replace("\t", 'X', $subject);
    }
}

?>
