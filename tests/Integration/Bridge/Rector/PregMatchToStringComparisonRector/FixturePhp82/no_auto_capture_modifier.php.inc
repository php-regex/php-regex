<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\FixturePhp82;

final class NoAutoCaptureModifier
{
    public function run(string $subject): bool
    {
        return (bool) preg_match('/abc/n', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\FixturePhp82;

final class NoAutoCaptureModifier
{
    public function run(string $subject): bool
    {
        return \str_contains($subject, 'abc');
    }
}

?>
