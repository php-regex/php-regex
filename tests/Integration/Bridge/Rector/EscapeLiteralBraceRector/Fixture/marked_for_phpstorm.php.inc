<?php

namespace JetBrains\PhpStorm {
    #[\Attribute(\Attribute::TARGET_PARAMETER)]
    final class Language
    {
        public function __construct(string $languageName)
        {
        }
    }
}

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture {
    use JetBrains\PhpStorm\Language;

    function matches_for_phpstorm(string $subject, #[Language('RegExp')] string $pattern, #[Language('JSON')] string $json = ''): bool
    {
        return 1 === preg_match($pattern, $subject);
    }

    final class MarkedForPhpStorm
    {
        public function run(string $subject): bool
        {
            return matches_for_phpstorm('/b{,3}/', '/c{,3}/', '/d{,3}/');
        }
    }
}

?>
-----
<?php

namespace JetBrains\PhpStorm {
    #[\Attribute(\Attribute::TARGET_PARAMETER)]
    final class Language
    {
        public function __construct(string $languageName)
        {
        }
    }
}

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture {
    use JetBrains\PhpStorm\Language;

    function matches_for_phpstorm(string $subject, #[Language('RegExp')] string $pattern, #[Language('JSON')] string $json = ''): bool
    {
        return 1 === preg_match($pattern, $subject);
    }

    final class MarkedForPhpStorm
    {
        public function run(string $subject): bool
        {
            return matches_for_phpstorm('/b{,3}/', '/c\{,3}/', '/d{,3}/');
        }
    }
}

?>
