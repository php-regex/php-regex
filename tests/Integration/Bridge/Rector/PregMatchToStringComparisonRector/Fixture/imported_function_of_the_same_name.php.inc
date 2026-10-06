<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

use function Other\in_array;

final class ImportedFunctionOfTheSameName
{
    public function run(string $subject): bool
    {
        return preg_match('/^(?:GET|POST)\z/', $subject) === 1;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

use function Other\in_array;

final class ImportedFunctionOfTheSameName
{
    public function run(string $subject): bool
    {
        return \in_array($subject, ['GET', 'POST'], true);
    }
}

?>
