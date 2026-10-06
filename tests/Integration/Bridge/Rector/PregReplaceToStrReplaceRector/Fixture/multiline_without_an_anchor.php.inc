<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class MultilineWithoutAnAnchor
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/m', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class MultilineWithoutAnAnchor
{
    public function run(string $subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
