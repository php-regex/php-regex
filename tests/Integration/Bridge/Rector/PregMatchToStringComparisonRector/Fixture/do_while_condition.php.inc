<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class DoWhileCondition
{
    public function run(string $subject): int
    {
        $runs = 0;
        do {
            ++$runs;
            if ($runs > 2) {
                break;
            }
        } while (preg_match('/foo/', $subject));

        return $runs;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class DoWhileCondition
{
    public function run(string $subject): int
    {
        $runs = 0;
        do {
            ++$runs;
            if ($runs > 2) {
                break;
            }
        } while (\str_contains($subject, 'foo'));

        return $runs;
    }
}

?>
