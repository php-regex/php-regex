<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class RawNul
{
    public function run(string $subject): bool
    {
        return 1 === preg_match("/a\0b/", $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class RawNul
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a\x00b/', $subject);
    }
}

?>
