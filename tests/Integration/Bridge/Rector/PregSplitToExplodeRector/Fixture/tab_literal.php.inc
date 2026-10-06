<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class TabLiteral
{
    public function run(string $subject): array
    {
        return preg_split('/\t/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class TabLiteral
{
    public function run(string $subject): array
    {
        return \explode("\t", $subject);
    }
}

?>
