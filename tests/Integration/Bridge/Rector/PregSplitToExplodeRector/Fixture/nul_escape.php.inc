<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class NulEscape
{
    public function run(string $subject): array
    {
        return preg_split('/\x00/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class NulEscape
{
    public function run(string $subject): array
    {
        return \explode("\x00", $subject);
    }
}

?>
