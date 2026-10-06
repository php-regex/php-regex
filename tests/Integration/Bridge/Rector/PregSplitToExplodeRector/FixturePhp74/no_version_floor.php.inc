<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\FixturePhp74;

final class NoVersionFloor
{
    public function run(string $subject): array
    {
        return preg_split('/,/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\FixturePhp74;

final class NoVersionFloor
{
    public function run(string $subject): array
    {
        return \explode(',', $subject);
    }
}

?>
