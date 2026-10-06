<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ClassOfOneMember
{
    public function run(string $subject): string
    {
        return preg_replace('/[x]y/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ClassOfOneMember
{
    public function run(string $subject): string
    {
        return \str_replace('xy', 'X', $subject);
    }
}

?>
