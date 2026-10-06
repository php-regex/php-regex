<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ZeroLiteral
{
    public function run(string $subject): array
    {
        return preg_split('/0/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ZeroLiteral
{
    public function run(string $subject): array
    {
        return \explode('0', $subject);
    }
}

?>
