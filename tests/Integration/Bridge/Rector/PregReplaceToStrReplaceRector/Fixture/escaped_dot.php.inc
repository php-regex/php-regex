<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class EscapedDot
{
    public function run(string $subject): string
    {
        return preg_replace('/f\.o/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class EscapedDot
{
    public function run(string $subject): string
    {
        return \str_replace('f.o', 'X', $subject);
    }
}

?>
