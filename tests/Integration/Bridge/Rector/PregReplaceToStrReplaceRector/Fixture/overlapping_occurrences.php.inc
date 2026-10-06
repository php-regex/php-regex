<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class OverlappingOccurrences
{
    public function run(string $subject): string
    {
        return preg_replace('/aa/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class OverlappingOccurrences
{
    public function run(string $subject): string
    {
        return \str_replace('aa', 'X', $subject);
    }
}

?>
