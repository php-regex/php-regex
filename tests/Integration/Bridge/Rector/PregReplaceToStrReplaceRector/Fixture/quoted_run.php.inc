<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class QuotedRun
{
    public function run(string $subject): string
    {
        return preg_replace('/\Qa.b\E/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class QuotedRun
{
    public function run(string $subject): string
    {
        return \str_replace('a.b', 'X', $subject);
    }
}

?>
