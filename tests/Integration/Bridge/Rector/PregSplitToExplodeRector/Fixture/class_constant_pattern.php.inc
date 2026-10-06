<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ClassConstantPattern
{
    private const PATTERN = '/,/';

    public function run(string $subject): array
    {
        return preg_split(self::PATTERN, $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ClassConstantPattern
{
    private const PATTERN = '/,/';

    public function run(string $subject): array
    {
        return \explode(',', $subject);
    }
}

?>
