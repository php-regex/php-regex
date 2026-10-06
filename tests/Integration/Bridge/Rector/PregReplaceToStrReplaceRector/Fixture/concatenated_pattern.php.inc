<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ConcatenatedPattern
{
    private const WORD = 'foo';

    public function run(string $subject): string
    {
        return preg_replace('/'.self::WORD.'/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ConcatenatedPattern
{
    private const WORD = 'foo';

    public function run(string $subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
