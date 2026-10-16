<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class RawNulBeforeADigit
{
    public function run(string $subject): ?string
    {
        return preg_replace("/\0001/", "-", $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class RawNulBeforeADigit
{
    public function run(string $subject): ?string
    {
        return preg_replace('/\x001/', "-", $subject);
    }
}

?>
