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

namespace PHPRegex\Linter\Extraction;

/**
 * How the PHP source wrote a pattern: a double-quoted "\t" reaches the
 * pattern as a raw tab, which the source still spells with an escape.
 *
 * @internal
 */
final class LiteralSpelling
{
    /**
     * Whether the string literals of the expression that starts at the
     * offset of the code, joined by ".", hold the character as written:
     * the quotes, heredocs and nowdocs read up to the first token that is
     * neither a literal, a ".", white space nor a comment. False when no
     * literal starts there.
     */
    public static function holdsRaw(string $code, int $offset, string $character): bool
    {
        $written = '';
        $expectsLiteral = true;
        $tokens = \PhpToken::tokenize('<?php '.substr($code, $offset));
        for ($i = 1, $count = \count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            if (!$expectsLiteral) {
                if ('.' !== $token->text) {
                    break;
                }

                $expectsLiteral = true;

                continue;
            }

            if ($token->is(\T_CONSTANT_ENCAPSED_STRING)) {
                $written .= $token->text;
            } elseif ($token->is(\T_START_HEREDOC)) {
                for ($i++; $i < $count && !$tokens[$i]->is(\T_END_HEREDOC); $i++) {
                    $written .= $tokens[$i]->text;
                }
            } else {
                break;
            }

            $expectsLiteral = false;
        }

        return '' !== $character && str_contains($written, $character);
    }
}
