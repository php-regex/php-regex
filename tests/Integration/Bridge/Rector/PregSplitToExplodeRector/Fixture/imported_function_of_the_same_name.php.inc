<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

use function Other\explode;

final class ImportedFunctionOfTheSameName
{
    public function run(string $subject): array
    {
        return preg_split('/,/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

use function Other\explode;

final class ImportedFunctionOfTheSameName
{
    public function run(string $subject): array
    {
        return \explode(',', $subject);
    }
}

?>
