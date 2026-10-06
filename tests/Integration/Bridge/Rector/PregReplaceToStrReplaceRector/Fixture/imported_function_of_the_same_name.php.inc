<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

use function Other\str_replace;

final class ImportedFunctionOfTheSameName
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

use function Other\str_replace;

final class ImportedFunctionOfTheSameName
{
    public function run(string $subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
