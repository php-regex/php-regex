<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ZeroLimit
{
    public function run(string $subject): array
    {
        return preg_split('/,/', $subject, 0);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ZeroLimit
{
    public function run(string $subject): array
    {
        return \explode(',', $subject);
    }
}

?>
