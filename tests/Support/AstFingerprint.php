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
 * RegexParser::CACHE_VERSION is this fingerprint. A cached tree or DFA is
 * only worth restoring while the current code would build the same one, so
 * the version is not a number somebody remembers to raise — it is computed
 * from the lexer, the parser, the nodes, the validator and the readers they
 * use, and from the code that turns a tree into a DFA.
 *
 * Comments and formatting are dropped: only what runs can change a tree, so
 * rewording a docblock costs nobody their cache.
 *
 * `task cache-version` writes it into src/Parser/RegexParser.php, and the
 * test suite fails while the two disagree.
 */
final class AstFingerprint
{
    /**
     * Everything whose behaviour decides what a pattern parses into.
     */
    private const SOURCES = [
        'src/Parser/Lexer.php',
        'src/Parser/Lexing/*.php',
        'src/Parser/Syntax/TokenParser.php',
        'src/Parser/Token/Token.php',
        'src/Parser/Token/TokenStream.php',
        'src/Parser/Token/TokenType.php',
        'src/Parser/Node/*.php',
        'src/Parser/Validation/Validator.php',
        'src/Parser/Validation/FirstErrorLocator.php',
        'src/Parser/Validation/CompiledLength.php',
        'src/Parser/Validation/CompiledSize.php',
        'src/Parser/Validation/EscapeChecks.php',
        'src/Parser/Validation/LookbehindChecks.php',
        'src/Parser/Validation/QuantifierCounts.php',
        'src/Parser/Validation/ReferenceChecks.php',
        'src/Parser/Validation/VerbChecks.php',
        'src/Parser/Validation/ClassChecks.php',
        'src/Parser/Validation/RangeChecks.php',
        'src/Parser/Validation/ConditionalChecks.php',
        'src/Parser/Validation/QuantifierChecks.php',
        'src/Parser/Validation/CalloutChecks.php',
        'src/Parser/Validation/ClassLength.php',
        'src/Parser/Validation/ExtendedClassLength.php',
        'src/Parser/Validation/OtherCases.php',
        'src/Parser/Validation/OtherCaseTables.php',
        'src/Parser/Validation/PcreTables.php',
        // Hands the flags, the delimiter and the options to the parser; the
        // version it holds is left out of its own fingerprint.
        'src/Parser/RegexParser.php',
        // Draws the snippet of a runtime compile error the parser reports.
        'src/Parser/Exception/Snippet.php',
        // Holds the default recursion depth the token parser reads.
        'src/Parser/ParserOptions.php',
        'src/Parser/Analysis/LengthRangeCalculator.php',
        'src/Parser/Analysis/AcceptVerb.php',
        'src/Parser/Engine/PcreEngine.php',
        'src/Parser/PcreTarget.php',
        'src/Parser/PcreFeature.php',
        // Walk the condition of a conditional when the solver asks which
        // runs prove a rewrite for every call.
        'src/Parser/NodeWalker.php',
        'src/Parser/TraversalAction.php',
        'src/Parser/Analysis/GroupNumbering.php',
        'src/Parser/Analysis/GroupNumberingCollector.php',
        'src/Parser/Analysis/GroupIndex.php',
        'src/Parser/Analysis/LookbehindLength.php',
        'src/Parser/Engine/LibraryPcre.php',
        // The character tables of the locale judged decide the size verdict.
        'src/Parser/Engine/CharacterTables.php',
        'src/Parser/Engine/NoJit.php',
        'src/Parser/PhpVersionGates.php',
        'src/Parser/Syntax/BadEscape.php',
        'src/Parser/Syntax/Callout.php',
        'src/Parser/Syntax/ClassParser.php',
        'src/Parser/Syntax/CodePointReader.php',
        'src/Parser/Syntax/ConditionParser.php',
        'src/Parser/Syntax/Delimiters.php',
        'src/Parser/Syntax/ExtendedClassParser.php',
        'src/Parser/Syntax/ExtendedClassReader.php',
        'src/Parser/Syntax/GroupNameReader.php',
        'src/Parser/Syntax/InlineFlags.php',
        'src/Parser/Syntax/NameCharacters.php',
        'src/Parser/Syntax/NamedCharacterBrace.php',
        'src/Parser/Syntax/ParseState.php',
        'src/Parser/Syntax/PatternParser.php',
        'src/Parser/Syntax/PcreNumber.php',
        'src/Parser/Syntax/PcreVerb.php',
        'src/Parser/Syntax/PosixItem.php',
        'src/Parser/Syntax/PropertyNameTables.php',
        'src/Parser/Syntax/PropertyNames.php',
        'src/Parser/Syntax/RepeatCount.php',
        'src/Parser/Syntax/StartOptions.php',
        'src/Parser/Syntax/VersionCondition.php',
        'src/Parser/Text/Ascii.php',
        'src/Parser/Text/Bytes.php',
        'src/Parser/Text/NearMiss.php',
        'src/Parser/Text/Utf8.php',
        // The HIR and the automata built from it: a persistent DFA cache is
        // keyed on this version too, so a change there must drop it.
        'src/Parser/Hir/*.php',
        'src/Automata/LanguageSolver.php',
        'src/Automata/Builder/*.php',
        'src/Automata/Determinization/*.php',
        'src/Automata/Minimization/*.php',
        'src/Automata/Model/*.php',
        'src/Automata/Options/*.php',
        'src/Automata/Solver/*.php',
        'src/Automata/Support/*.php',
        'src/Automata/Transform/*.php',
        'src/Automata/Unicode/*.php',
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

            // The version itself: writing it must not change it again.
            if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match('/^\'ast-[0-9a-f]{32}\'$/', $token[1])) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }
}
