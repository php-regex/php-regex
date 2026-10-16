<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class AtomicGroup
{
    public function run(string $subject): ?string
    {
        return preg_replace('/(*atomic:a|ab)c/', 'x', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class AtomicGroup
{
    public function run(string $subject): ?string
    {
        return preg_replace('/(?>a|ab)c/', 'x', $subject);
    }
}

?>
