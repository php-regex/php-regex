<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class FixedRepetition
{
    public function run(string $subject): string
    {
        return preg_replace('/a{3}/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class FixedRepetition
{
    public function run(string $subject): string
    {
        return \str_replace('aaa', 'X', $subject);
    }
}

?>
