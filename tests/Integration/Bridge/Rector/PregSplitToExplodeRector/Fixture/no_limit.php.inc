<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class NoLimit
{
    public function run(string $subject): array
    {
        return preg_split('/,/', $subject, -1);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class NoLimit
{
    public function run(string $subject): array
    {
        return \explode(',', $subject);
    }
}

?>
