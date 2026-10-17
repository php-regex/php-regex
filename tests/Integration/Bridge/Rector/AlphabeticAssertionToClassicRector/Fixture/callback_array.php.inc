<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class CallbackArray
{
    public function run(string $subject): ?string
    {
        return preg_replace_callback_array([
            '/(*pla:foo)\w+/' => static fn (array $match): string => 'x',
            '/b/' => static fn (array $match): string => 'y',
        ], $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class CallbackArray
{
    public function run(string $subject): ?string
    {
        return preg_replace_callback_array([
            '/(?=foo)\w+/' => static fn (array $match): string => 'x',
            '/b/' => static fn (array $match): string => 'y',
        ], $subject);
    }
}

?>
