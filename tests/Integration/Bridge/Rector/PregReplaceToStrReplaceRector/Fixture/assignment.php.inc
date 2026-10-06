<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class Assignment
{
    public function run(string $subject): string
    {
        $result = preg_replace('/foo/', 'bar', $subject);

        return $result;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class Assignment
{
    public function run(string $subject): string
    {
        $result = \str_replace('foo', 'bar', $subject);

        return $result;
    }
}

?>
