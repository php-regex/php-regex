<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class Literal
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

final class Literal
{
    public function run(string $subject): array
    {
        return \explode(',', $subject);
    }
}

?>
