<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class RawNulInAClass
{
    public function run(string $subject): array|false
    {
        return preg_split("/[\0,]/", $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class RawNulInAClass
{
    public function run(string $subject): array|false
    {
        return preg_split('/[\x00,]/', $subject);
    }
}

?>
