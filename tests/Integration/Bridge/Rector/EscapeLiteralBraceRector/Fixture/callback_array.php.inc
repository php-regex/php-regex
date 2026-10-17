<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class CallbackArray
{
    public function run(string $subject): ?string
    {
        return preg_replace_callback_array([
            '/a{,3}/' => static fn (array $match): string => 'x',
            '/b{1,2}/' => static fn (array $match): string => 'y',
        ], $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class CallbackArray
{
    public function run(string $subject): ?string
    {
        return preg_replace_callback_array([
            '/a\{,3}/' => static fn (array $match): string => 'x',
            '/b{1,2}/' => static fn (array $match): string => 'y',
        ], $subject);
    }
}

?>
