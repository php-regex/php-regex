<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class TwoByteLiteral
{
    public function run(string $subject): array
    {
        return preg_split('/ab/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class TwoByteLiteral
{
    public function run(string $subject): array
    {
        return \explode('ab', $subject);
    }
}

?>
