<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

function explode(string $separator, string $string): array
{
    throw new \LogicException('The global explode() is meant.');
}

final class NamespacedFunctionOfTheSameName
{
    public function run(string $subject): array
    {
        return preg_split('/,/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

function explode(string $separator, string $string): array
{
    throw new \LogicException('The global explode() is meant.');
}

final class NamespacedFunctionOfTheSameName
{
    public function run(string $subject): array
    {
        return \explode(',', $subject);
    }
}

?>
