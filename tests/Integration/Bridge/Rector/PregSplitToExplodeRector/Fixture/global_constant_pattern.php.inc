<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

const SEPARATOR = '/;/';

final class GlobalConstantPattern
{
    public function run(string $subject): array
    {
        return preg_split(SEPARATOR, $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

const SEPARATOR = '/;/';

final class GlobalConstantPattern
{
    public function run(string $subject): array
    {
        return \explode(';', $subject);
    }
}

?>
