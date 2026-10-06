<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class CaptureAndEscape
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/(f\.o)/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class CaptureAndEscape
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'f.o');
    }
}

?>
