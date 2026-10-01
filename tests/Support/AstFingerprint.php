<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Support;

/**
 * A fingerprint of the code that decides what a pattern parses into.
 *
 * Regex::CACHE_VERSION is this fingerprint. A cached tree is only worth
 * restoring while the current code would build the same one, so the version
 * is not a number somebody remembers to raise — it is computed from the
 * lexer, the parser, the nodes and the readers they use.
 *
 * Comments and formatting are dropped: only what runs can change a tree, so
 * rewording a docblock costs nobody their cache.
 *
 * `task cache-version` writes it into src/Toolkit/Regex.php, and the test suite
 * fails while the two disagree.
 */
final class AstFingerprint
{
    /**
     * Everything whose behaviour decides what a pattern parses into.
     */
    private const SOURCES = [
        'src/Parser/Lexer.php',
        'src/Parser/Syntax/TokenParser.php',
        'src/Parser/Token/Token.php',
        'src/Parser/Token/TokenStream.php',
        'src/Parser/Token/TokenType.php',
        'src/Parser/Node/*.php',
        'src/Parser/Validation/Validator.php',
        'src/Parser/PcreTarget.php',
        'src/Parser/PcreFeature.php',
        'src/Parser/Analysis/GroupNumbering.php',
        'src/Parser/Analysis/GroupNumberingCollector.php',
        'src/Parser/Internal/CodePointReader.php',
        'src/Parser/Internal/ExtendedClassReader.php',
        'src/Parser/Internal/GroupNameReader.php',
        'src/Parser/Internal/InlineFlags.php',
        'src/Parser/Internal/PatternParser.php',
        'src/Parser/Internal/PcreVerb.php',
        'src/Parser/Internal/VersionCondition.php',
    ];

    public static function compute(): string
    {
        $root = self::root();
        $parts = [];

        foreach (self::files() as $file) {
            $parts[] = substr($file, \strlen($root) + 1)."\0".self::meaningfulCode((string) file_get_contents($file));
        }

        return 'ast-'.substr(hash('sha256', implode("\n", $parts)), 0, 32);
    }

    /**
     * @return list<string>
     */
    public static function files(): array
    {
        $root = self::root();
        $files = [];

        foreach (self::SOURCES as $source) {
            foreach ((array) glob($root.'/'.$source) as $file) {
                $files[] = (string) $file;
            }
        }

        sort($files);

        return $files;
    }

    public static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * The file with everything that cannot change behaviour taken out.
     */
    private static function meaningfulCode(string $php): string
    {
        $code = '';

        foreach (token_get_all($php) as $token) {
            if (!\is_array($token)) {
                $code .= $token;

                continue;
            }

            if (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE], true)) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }
}
