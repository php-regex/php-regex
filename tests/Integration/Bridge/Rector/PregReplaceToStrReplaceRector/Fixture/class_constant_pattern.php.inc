<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ClassConstantPattern
{
    private const PATTERN = '/foo/';

    public function run(string $subject): string
    {
        return preg_replace(self::PATTERN, 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class ClassConstantPattern
{
    private const PATTERN = '/foo/';

    public function run(string $subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
