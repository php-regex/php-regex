<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

function str_replace(string $search, string $replace, string $subject): string
{
    throw new \LogicException('The global str_replace() is meant.');
}

final class NamespacedFunctionOfTheSameName
{
    public function run(string $subject): string
    {
        return preg_replace('/foo/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

function str_replace(string $search, string $replace, string $subject): string
{
    throw new \LogicException('The global str_replace() is meant.');
}

final class NamespacedFunctionOfTheSameName
{
    public function run(string $subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
