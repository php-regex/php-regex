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

// What each pattern reads as for PHP 8.4 with the PCRE2 releases named: whether
// that release compiles it, its tokens (type, value, offset, length), the
// digest of its tree as JsonDumper exports it or the error parse() stops on,
// and the error validate() reports, each error as "code@offset: message".

return [
    'text, two deep' => [
        'pattern' => '/(*pla:(*nla:a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)', 0, 15], ['eof', '', 15, 0]],
                'tree' => '46dbe40a77ea65fd',
                'error' => null,
            ],
        ],
    ],
    'text, two deep, under x' => [
        'pattern' => '/(*pla:(*nla:a))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)', 0, 15], ['eof', '', 15, 0]],
                'tree' => 'c1d423d0cce5ca59',
                'error' => null,
            ],
        ],
    ],
    'text, two deep, under u' => [
        'pattern' => '/(*pla:(*nla:a))/u',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)', 0, 15], ['eof', '', 15, 0]],
                'tree' => 'd5de4ad9b8441f78',
                'error' => null,
            ],
        ],
    ],
    'text, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a)z)w', 0, 26], ['eof', '', 26, 0]],
                'tree' => 'b6404137d0dd9d93',
                'error' => null,
            ],
        ],
    ],
    'text, three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a)z)w', 0, 26], ['eof', '', 26, 0]],
                'tree' => '8f1779a4f9658f4e',
                'error' => null,
            ],
        ],
    ],
    'text, three deep, under u' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a)z)w)/u',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a)z)w', 0, 26], ['eof', '', 26, 0]],
                'tree' => '0b2830588e493dba',
                'error' => null,
            ],
        ],
    ],
    'text, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:a)', 0, 14], ['eof', '', 14, 0]],
                'tree' => '6b2e15e0073dc9ce',
                'error' => null,
            ],
        ],
    ],
    'text, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:a))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:a)', 2, 15], ['literal', 'a', 17, 1], ['alternation', '|', 18, 1], ['literal', 'b', 19, 1], ['group_close', ')', 20, 1], ['eof', '', 21, 0]],
                'tree' => '2713ec0347d86126',
                'error' => null,
            ],
        ],
    ],
    'text, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:a)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@14: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@14: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@14: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'text, two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:a)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@14: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@14: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@14: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'text, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'text, three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'nothing, two deep' => [
        'pattern' => '/(*pla:(*nla:))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:)', 0, 14], ['eof', '', 14, 0]],
                'tree' => '65ace3251e24ef35',
                'error' => null,
            ],
        ],
    ],
    'nothing, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:)z)w', 0, 25], ['eof', '', 25, 0]],
                'tree' => '4aa99d3cb96a4707',
                'error' => null,
            ],
        ],
    ],
    'nothing, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:)', 0, 13], ['eof', '', 13, 0]],
                'tree' => '5cd1686e3f394a4e',
                'error' => null,
            ],
        ],
    ],
    'nothing, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:)', 2, 14], ['literal', 'a', 16, 1], ['alternation', '|', 17, 1], ['literal', 'b', 18, 1], ['group_close', ')', 19, 1], ['eof', '', 20, 0]],
                'tree' => '437459219fe21e40',
                'error' => null,
            ],
        ],
    ],
    'nothing, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@13: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@13: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@13: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'nothing, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'alternation, two deep' => [
        'pattern' => '/(*pla:(*nla:a|b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a|b)', 0, 17], ['eof', '', 17, 0]],
                'tree' => 'c8ff10bf8309840c',
                'error' => null,
            ],
        ],
    ],
    'alternation, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a|b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a|b)z)w', 0, 28], ['eof', '', 28, 0]],
                'tree' => '38f6032b4d5057c2',
                'error' => null,
            ],
        ],
    ],
    'capturing group and its reference, two deep' => [
        'pattern' => "/(*pla:(*nla:(a)\\1))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:(a)\\1)", 0, 19], ['eof', '', 19, 0]],
                'tree' => '85b11e8f3b1f673b',
                'error' => null,
            ],
        ],
    ],
    'capturing group and its reference, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:(a)\\1)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:(a)\\1)z)w", 0, 30], ['eof', '', 30, 0]],
                'tree' => '9c23adefa82f1104',
                'error' => null,
            ],
        ],
    ],
    'named group and its reference, two deep' => [
        'pattern' => "/(*pla:(*nla:(?<n>a)\\k<n>))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:(?<n>a)\\k<n>)", 0, 26], ['eof', '', 26, 0]],
                'tree' => '9586e692472509d2',
                'error' => null,
            ],
        ],
    ],
    'named group and its reference, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:(?<n>a)\\k<n>)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:(?<n>a)\\k<n>)z)w", 0, 37], ['eof', '', 37, 0]],
                'tree' => '286febe5df0ba3ac',
                'error' => null,
            ],
        ],
    ],
    'comment holding "(", two deep' => [
        'pattern' => '/(*pla:(*nla:a(?#c(d)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(?#c(d)b)', 0, 23], ['eof', '', 23, 0]],
                'tree' => '1c057d78e4a6efeb',
                'error' => null,
            ],
        ],
    ],
    'comment holding "(", two deep, under x' => [
        'pattern' => '/(*pla:(*nla:a(?#c(d)b))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(?#c(d)b)', 0, 23], ['eof', '', 23, 0]],
                'tree' => '15f168c7fbd2f821',
                'error' => null,
            ],
        ],
    ],
    'comment holding "(", three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?#c(d)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(?#c(d)b)z)w', 0, 34], ['eof', '', 34, 0]],
                'tree' => '401e2550ed85b36a',
                'error' => null,
            ],
        ],
    ],
    'comment holding "(", three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?#c(d)b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(?#c(d)b)z)w', 0, 34], ['eof', '', 34, 0]],
                'tree' => '997a8ca48b3f97ac',
                'error' => null,
            ],
        ],
    ],
    'comment holding "(", two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:a(?#c(d)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:a(?#c(d)b)', 0, 22], ['eof', '', 22, 0]],
                'tree' => '2ba658181312197e',
                'error' => null,
            ],
        ],
    ],
    'comment holding "(", as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:a(?#c(d)b))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:a(?#c(d)b)', 2, 23], ['literal', 'a', 25, 1], ['alternation', '|', 26, 1], ['literal', 'b', 27, 1], ['group_close', ')', 28, 1], ['eof', '', 29, 0]],
                'tree' => '5dc0b1f500a798b8',
                'error' => null,
            ],
        ],
    ],
    'comment holding "(", two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:a(?#c(d)b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment holding "(", two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:a(?#c(d)b)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment holding "(", three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(?#c(d)b/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment holding "(", three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(?#c(d)b/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted ")", two deep' => [
        'pattern' => "/(*pla:(*nla:a\\Q)\\Eb))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a\\Q)\\Eb)", 0, 21], ['eof', '', 21, 0]],
                'tree' => 'c5dfdfa28fa5af32',
                'error' => null,
            ],
        ],
    ],
    'quoted ")", two deep, under x' => [
        'pattern' => "/(*pla:(*nla:a\\Q)\\Eb))/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a\\Q)\\Eb)", 0, 21], ['eof', '', 21, 0]],
                'tree' => '283dbfe78bfaa597',
                'error' => null,
            ],
        ],
    ],
    'quoted ")", three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\Q)\\Eb)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\\Q)\\Eb)z)w", 0, 32], ['eof', '', 32, 0]],
                'tree' => 'c02afe76e088384c',
                'error' => null,
            ],
        ],
    ],
    'quoted ")", three deep, under x' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\Q)\\Eb)z)w)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\\Q)\\Eb)z)w", 0, 32], ['eof', '', 32, 0]],
                'tree' => '314bae3ab1e36f07',
                'error' => null,
            ],
        ],
    ],
    'quoted ")", two deep in a script run' => [
        'pattern' => "/(*sr:(*plb:a\\Q)\\Eb))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "sr:(*plb:a\\Q)\\Eb)", 0, 20], ['eof', '', 20, 0]],
                'tree' => 'bf1be638cb22e210',
                'error' => null,
            ],
        ],
    ],
    'quoted ")", as the condition, two deep' => [
        'pattern' => "/(?(*pla:(*pla:a\\Q)\\Eb))a|b)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', "pla:(*pla:a\\Q)\\Eb)", 2, 21], ['literal', 'a', 23, 1], ['alternation', '|', 24, 1], ['literal', 'b', 25, 1], ['group_close', ')', 26, 1], ['eof', '', 27, 0]],
                'tree' => 'b00745bf11d0a220',
                'error' => null,
            ],
        ],
    ],
    'quoted ")", two deep, the outer body left open' => [
        'pattern' => "/(*pla:(*nla:a\\Q)\\Eb)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted ")", two deep, the outer body left open, under x' => [
        'pattern' => "/(*pla:(*nla:a\\Q)\\Eb)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted ")", three deep, the bodies left open' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a\\Q)\\Eb/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted ")", three deep, the bodies left open, under x' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a\\Q)\\Eb/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'empty quoted run, two deep' => [
        'pattern' => "/(*pla:(*nla:\\Q\\Ea))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\\Q\\Ea)", 0, 19], ['eof', '', 19, 0]],
                'tree' => '29a5e82503ec59e9',
                'error' => null,
            ],
        ],
    ],
    'empty quoted run, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\\Q\\Ea)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\\Q\\Ea)z)w", 0, 30], ['eof', '', 30, 0]],
                'tree' => '870a992c44a1e10f',
                'error' => null,
            ],
        ],
    ],
    'escaped ")", two deep' => [
        'pattern' => "/(*pla:(*nla:a\\)b))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a\\)b)", 0, 18], ['eof', '', 18, 0]],
                'tree' => 'eeb9033b5ffae2fc',
                'error' => null,
            ],
        ],
    ],
    'escaped ")", three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\)b)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\\)b)z)w", 0, 29], ['eof', '', 29, 0]],
                'tree' => 'a25f6e62f916b402',
                'error' => null,
            ],
        ],
    ],
    'class holding ")", two deep' => [
        'pattern' => '/(*pla:(*nla:[)]a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[)]a)', 0, 18], ['eof', '', 18, 0]],
                'tree' => '8815085ea62888d4',
                'error' => null,
            ],
        ],
    ],
    'class holding ")", two deep, under x' => [
        'pattern' => '/(*pla:(*nla:[)]a))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[)]a)', 0, 18], ['eof', '', 18, 0]],
                'tree' => '9d4d12bf747302d3',
                'error' => null,
            ],
        ],
    ],
    'class holding ")", three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[)]a)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[)]a)z)w', 0, 29], ['eof', '', 29, 0]],
                'tree' => '9fa970b36d39f127',
                'error' => null,
            ],
        ],
    ],
    'class holding ")", three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[)]a)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[)]a)z)w', 0, 29], ['eof', '', 29, 0]],
                'tree' => '3910ea39ba6cbde5',
                'error' => null,
            ],
        ],
    ],
    'class holding ")", two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:[)]a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:[)]a)', 0, 17], ['eof', '', 17, 0]],
                'tree' => 'fa9821fa89cf3f94',
                'error' => null,
            ],
        ],
    ],
    'class holding ")", as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:[)]a))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:[)]a)', 2, 18], ['literal', 'a', 20, 1], ['alternation', '|', 21, 1], ['literal', 'b', 22, 1], ['group_close', ')', 23, 1], ['eof', '', 24, 0]],
                'tree' => 'e0d5be5c80da526b',
                'error' => null,
            ],
        ],
    ],
    'class holding ")", two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:[)]a)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'class holding ")", two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:[)]a)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'class holding ")", three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:[)]a/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'class holding ")", three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:[)]a/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'class opening with "]", two deep' => [
        'pattern' => '/(*pla:(*nla:[])]a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[])]a)', 0, 19], ['eof', '', 19, 0]],
                'tree' => 'deff8c7953b5aa81',
                'error' => null,
            ],
        ],
    ],
    'class opening with "]", three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[])]a)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[])]a)z)w', 0, 30], ['eof', '', 30, 0]],
                'tree' => '34a114979399c173',
                'error' => null,
            ],
        ],
    ],
    'negated class opening with "]", two deep' => [
        'pattern' => '/(*pla:(*nla:[^])]a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[^])]a)', 0, 20], ['eof', '', 20, 0]],
                'tree' => 'eacb8f5e82c9d53b',
                'error' => null,
            ],
        ],
    ],
    'negated class opening with "]", three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[^])]a)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[^])]a)z)w', 0, 31], ['eof', '', 31, 0]],
                'tree' => 'b16de7ef583ffd8c',
                'error' => null,
            ],
        ],
    ],
    'class holding a quoted "]", two deep' => [
        'pattern' => "/(*pla:(*nla:[\\Q])\\E]a))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:[\\Q])\\E]a)", 0, 23], ['eof', '', 23, 0]],
                'tree' => 'a47f21d35eb691f0',
                'error' => null,
            ],
        ],
    ],
    'class holding a quoted "]", three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:[\\Q])\\E]a)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:[\\Q])\\E]a)z)w", 0, 34], ['eof', '', 34, 0]],
                'tree' => 'c62e31dc87a56954',
                'error' => null,
            ],
        ],
    ],
    'class holding an escaped "]", two deep' => [
        'pattern' => "/(*pla:(*nla:[\\])]a))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:[\\])]a)", 0, 20], ['eof', '', 20, 0]],
                'tree' => 'd50333190ac41c0e',
                'error' => null,
            ],
        ],
    ],
    'class holding an escaped "]", three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:[\\])]a)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:[\\])]a)z)w", 0, 31], ['eof', '', 31, 0]],
                'tree' => '096de67464216f03',
                'error' => null,
            ],
        ],
    ],
    "class opening with \"\\E\", two deep" => [
        'pattern' => "/(*pla:(*nla:[\\E])]a))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:[\\E])]a)", 0, 21], ['eof', '', 21, 0]],
                'tree' => '8701323f6a18a4b8',
                'error' => null,
            ],
        ],
    ],
    "class opening with \"\\E\", three deep" => [
        'pattern' => "/(*pla:x(?*y(*atomic:[\\E])]a)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:[\\E])]a)z)w", 0, 32], ['eof', '', 32, 0]],
                'tree' => '3541d4b252a9eb98',
                'error' => null,
            ],
        ],
    ],
    'POSIX class, two deep' => [
        'pattern' => '/(*pla:(*nla:[[:alpha:])]))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[[:alpha:])])', 0, 26], ['eof', '', 26, 0]],
                'tree' => 'd7929b583977d376',
                'error' => null,
            ],
        ],
    ],
    'POSIX class, two deep, under u' => [
        'pattern' => '/(*pla:(*nla:[[:alpha:])]))/u',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[[:alpha:])])', 0, 26], ['eof', '', 26, 0]],
                'tree' => 'd8627067a10bcbcb',
                'error' => null,
            ],
        ],
    ],
    'POSIX class, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[[:alpha:])])z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[[:alpha:])])z)w', 0, 37], ['eof', '', 37, 0]],
                'tree' => 'a5b718c4ec304df2',
                'error' => null,
            ],
        ],
    ],
    'POSIX class, three deep, under u' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[[:alpha:])])z)w)/u',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[[:alpha:])])z)w', 0, 37], ['eof', '', 37, 0]],
                'tree' => '5e54544ea5819442',
                'error' => null,
            ],
        ],
    ],
    'POSIX class, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:[[:alpha:])]))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:[[:alpha:])])', 0, 25], ['eof', '', 25, 0]],
                'tree' => 'b7d6675d2fbb118b',
                'error' => null,
            ],
        ],
    ],
    'POSIX class, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:[[:alpha:])]))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:[[:alpha:])])', 2, 26], ['literal', 'a', 28, 1], ['alternation', '|', 29, 1], ['literal', 'b', 30, 1], ['group_close', ')', 31, 1], ['eof', '', 32, 0]],
                'tree' => 'cbb4357fffd532c6',
                'error' => null,
            ],
        ],
    ],
    'POSIX class, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:[[:alpha:])])/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'POSIX class, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:[[:alpha:])]/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@33: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@33: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@33: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'negated POSIX class, two deep' => [
        'pattern' => '/(*pla:(*nla:[[:^digit:])]))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[[:^digit:])])', 0, 27], ['eof', '', 27, 0]],
                'tree' => '8e704644bf22ff51',
                'error' => null,
            ],
        ],
    ],
    'negated POSIX class, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[[:^digit:])])z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[[:^digit:])])z)w', 0, 38], ['eof', '', 38, 0]],
                'tree' => '81ae3ee8079b30e2',
                'error' => null,
            ],
        ],
    ],
    'text that opens no POSIX class, two deep' => [
        'pattern' => '/(*pla:(*nla:[[:a)]b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:[[:a)]b)', 0, 21], ['eof', '', 21, 0]],
                'tree' => 'c7c8dded78232cee',
                'error' => null,
            ],
        ],
    ],
    'text that opens no POSIX class, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[[:a)]b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[[:a)]b)z)w', 0, 32], ['eof', '', 32, 0]],
                'tree' => '8d00aa646017ab0b',
                'error' => null,
            ],
        ],
    ],
    'POSIX class PCRE does not know, two deep' => [
        'pattern' => '/(*pla:(*nla:[[:foo:])]))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:[[:foo:])])', 0, 24], ['eof', '', 24, 0]],
                'tree' => '5b835611ddd62433',
                'error' => 'regex.posix.invalid@15: Invalid POSIX class: "foo".',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:[[:foo:])])', 0, 24], ['eof', '', 24, 0]],
                'tree' => '5b835611ddd62433',
                'error' => 'regex.posix.invalid@20: Invalid POSIX class: "foo".',
            ],
        ],
    ],
    'POSIX class PCRE does not know, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[[:foo:])])z)w)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[[:foo:])])z)w', 0, 35], ['eof', '', 35, 0]],
                'tree' => '1da7a735f7146253',
                'error' => 'regex.posix.invalid@23: Invalid POSIX class: "foo".',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[[:foo:])])z)w', 0, 35], ['eof', '', 35, 0]],
                'tree' => '1da7a735f7146253',
                'error' => 'regex.posix.invalid@28: Invalid POSIX class: "foo".',
            ],
        ],
    ],
    'callout number, two deep' => [
        'pattern' => '/(*pla:(*nla:a(?C7)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(?C7)b)', 0, 21], ['eof', '', 21, 0]],
                'tree' => '5039750a848a63c7',
                'error' => null,
            ],
        ],
    ],
    'callout number, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?C7)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(?C7)b)z)w', 0, 32], ['eof', '', 32, 0]],
                'tree' => 'b74e423f704f185e',
                'error' => null,
            ],
        ],
    ],
    'callout string holding ")", two deep' => [
        'pattern' => '/(*pla:(*nla:a(?C"x)y")b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(?C"x)y")b)', 0, 25], ['eof', '', 25, 0]],
                'tree' => '4c0f40ad010bb00f',
                'error' => null,
            ],
        ],
    ],
    'callout string holding ")", two deep, under x' => [
        'pattern' => '/(*pla:(*nla:a(?C"x)y")b))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(?C"x)y")b)', 0, 25], ['eof', '', 25, 0]],
                'tree' => '757661026286324c',
                'error' => null,
            ],
        ],
    ],
    'callout string holding ")", three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?C"x)y")b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(?C"x)y")b)z)w', 0, 36], ['eof', '', 36, 0]],
                'tree' => '7ba5eb367007608a',
                'error' => null,
            ],
        ],
    ],
    'callout string holding ")", three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?C"x)y")b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(?C"x)y")b)z)w', 0, 36], ['eof', '', 36, 0]],
                'tree' => '75d63c7925d2b6f8',
                'error' => null,
            ],
        ],
    ],
    'callout string holding ")", two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:a(?C"x)y")b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:a(?C"x)y")b)', 0, 24], ['eof', '', 24, 0]],
                'tree' => 'b217e78d2651f8c8',
                'error' => null,
            ],
        ],
    ],
    'callout string holding ")", as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:a(?C"x)y")b))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:a(?C"x)y")b)', 2, 25], ['literal', 'a', 27, 1], ['alternation', '|', 28, 1], ['literal', 'b', 29, 1], ['group_close', ')', 30, 1], ['eof', '', 31, 0]],
                'tree' => '951a9c77c5cb663c',
                'error' => null,
            ],
        ],
    ],
    'callout string holding ")", two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:a(?C"x)y")b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'callout string holding ")", two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:a(?C"x)y")b)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'callout string holding ")", three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(?C"x)y")b/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@32: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@32: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@32: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'callout string holding ")", three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(?C"x)y")b/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@32: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@32: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@32: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'callout string doubling its delimiter, two deep' => [
        'pattern' => "/(*pla:(*nla:(?C'a)''b')c))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:(?C'a)''b')c)", 0, 26], ['eof', '', 26, 0]],
                'tree' => 'c6f97cb1999b1a71',
                'error' => null,
            ],
        ],
    ],
    'callout string doubling its delimiter, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:(?C'a)''b')c)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:(?C'a)''b')c)z)w", 0, 37], ['eof', '', 37, 0]],
                'tree' => '118b18761c38be24',
                'error' => null,
            ],
        ],
    ],
    'callout number past 255, two deep' => [
        'pattern' => '/(*pla:(*nla:a(?C256)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(?C256)b)', 0, 23], ['eof', '', 23, 0]],
                'tree' => '83ebd5bbaf35fb14',
                'error' => 'regex.callout.out_of_range@19: Callout identifier must be between 0 and 255, got 256.',
            ],
        ],
    ],
    'callout number past 255, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?C256)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(?C256)b)z)w', 0, 34], ['eof', '', 34, 0]],
                'tree' => '3d1213c3f7a677ac',
                'error' => 'regex.callout.out_of_range@27: Callout identifier must be between 0 and 255, got 256.',
            ],
        ],
    ],
    'verb, two deep' => [
        'pattern' => '/(*pla:(*nla:a(*SKIP)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(*SKIP)b)', 0, 23], ['eof', '', 23, 0]],
                'tree' => 'ef41246bac6994c4',
                'error' => null,
            ],
        ],
    ],
    'verb, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(*SKIP)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(*SKIP)b)z)w', 0, 34], ['eof', '', 34, 0]],
                'tree' => '6ff5fa66b6f96c11',
                'error' => null,
            ],
        ],
    ],
    'mark holding "(", two deep' => [
        'pattern' => '/(*pla:(*nla:a(*MARK:n(m)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(*MARK:n(m)b)', 0, 27], ['eof', '', 27, 0]],
                'tree' => '17d4425ded6da28a',
                'error' => null,
            ],
        ],
    ],
    'mark holding "(", two deep, under x' => [
        'pattern' => '/(*pla:(*nla:a(*MARK:n(m)b))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(*MARK:n(m)b)', 0, 27], ['eof', '', 27, 0]],
                'tree' => '36b057391d87b2fe',
                'error' => null,
            ],
        ],
    ],
    'mark holding "(", three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(*MARK:n(m)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(*MARK:n(m)b)z)w', 0, 38], ['eof', '', 38, 0]],
                'tree' => '353e62c05ae096f2',
                'error' => null,
            ],
        ],
    ],
    'mark holding "(", three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(*MARK:n(m)b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(*MARK:n(m)b)z)w', 0, 38], ['eof', '', 38, 0]],
                'tree' => 'a97ef764ec8edb9c',
                'error' => null,
            ],
        ],
    ],
    'mark holding "(", two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:a(*MARK:n(m)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:a(*MARK:n(m)b)', 0, 26], ['eof', '', 26, 0]],
                'tree' => '30a9b335e9465354',
                'error' => null,
            ],
        ],
    ],
    'mark holding "(", as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:a(*MARK:n(m)b))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:a(*MARK:n(m)b)', 2, 27], ['literal', 'a', 29, 1], ['alternation', '|', 30, 1], ['literal', 'b', 31, 1], ['group_close', ')', 32, 1], ['eof', '', 33, 0]],
                'tree' => '72bd5092049b1c14',
                'error' => null,
            ],
        ],
    ],
    'mark holding "(", two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:a(*MARK:n(m)b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@26: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@26: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@26: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'mark holding "(", two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:a(*MARK:n(m)b)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@26: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@26: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@26: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'mark holding "(", three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(*MARK:n(m)b/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'mark holding "(", three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(*MARK:n(m)b/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'mark by its short spelling, two deep' => [
        'pattern' => '/(*pla:(*nla:(*:n)a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(*:n)a)', 0, 20], ['eof', '', 20, 0]],
                'tree' => '5ec4386bfba5dacc',
                'error' => null,
            ],
        ],
    ],
    'mark by its short spelling, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(*:n)a)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(*:n)a)z)w', 0, 31], ['eof', '', 31, 0]],
                'tree' => 'fceb28cf5d9cc37c',
                'error' => null,
            ],
        ],
    ],
    'verb PCRE does not know, two deep' => [
        'pattern' => '/(*pla:(*nla:a(*FOO)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(*FOO)b)', 0, 22], ['eof', '', 22, 0]],
                'tree' => '549042cbec052c98',
                'error' => 'regex.verb.invalid@18: Invalid or unsupported PCRE verb: "FOO".',
            ],
        ],
    ],
    'verb PCRE does not know, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(*FOO)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(*FOO)b)z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => 'a3d8ca0713f3811b',
                'error' => 'regex.verb.invalid@26: Invalid or unsupported PCRE verb: "FOO".',
            ],
        ],
    ],
    'alphabetic name without its colon, two deep' => [
        'pattern' => '/(*pla:(*nla:a(*pla)b))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(*pla)b)', 0, 22], ['eof', '', 22, 0]],
                'tree' => 'd359b9617038c76f',
                'error' => 'regex.verb.invalid@18: Invalid or unsupported PCRE verb: "pla".',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(*pla)b)', 0, 22], ['eof', '', 22, 0]],
                'tree' => 'd359b9617038c76f',
                'error' => 'regex.verb.invalid@19: Invalid or unsupported PCRE verb: "pla".',
            ],
        ],
    ],
    'alphabetic name without its colon, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(*pla)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(*pla)b)z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => '05a0105b9e39d357',
                'error' => 'regex.verb.invalid@26: Invalid or unsupported PCRE verb: "pla".',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(*pla)b)z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => '05a0105b9e39d357',
                'error' => 'regex.verb.invalid@27: Invalid or unsupported PCRE verb: "pla".',
            ],
        ],
    ],
    'two stars, two deep' => [
        'pattern' => '/(*pla:(*nla:a(**)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(**)b)', 0, 20], ['eof', '', 20, 0]],
                'tree' => 'regex.verb.invalid@15: Unknown verb at position 15: "(**" opens no verb, PCRE wants a name after "(*".',
                'error' => 'regex.verb.invalid@15: Unknown verb at position 15: "(**" opens no verb, PCRE wants a name after "(*".',
            ],
        ],
    ],
    'two stars, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(**)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(**)b)z)w', 0, 31], ['eof', '', 31, 0]],
                'tree' => 'regex.verb.invalid@23: Unknown verb at position 23: "(**" opens no verb, PCRE wants a name after "(*".',
                'error' => 'regex.verb.invalid@23: Unknown verb at position 23: "(**" opens no verb, PCRE wants a name after "(*".',
            ],
        ],
    ],
    'option turning x off, two deep' => [
        'pattern' => '/(*pla:(*nla:(?-x)a #b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?-x)a #b)', 0, 23], ['eof', '', 23, 0]],
                'tree' => '543fc09d93a235c6',
                'error' => null,
            ],
        ],
    ],
    'option turning x off, two deep, under x' => [
        'pattern' => '/(*pla:(*nla:(?-x)a #b))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?-x)a #b)', 0, 23], ['eof', '', 23, 0]],
                'tree' => '7942aadf539db6a2',
                'error' => null,
            ],
        ],
    ],
    'option turning x off, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?-x)a #b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?-x)a #b)z)w', 0, 34], ['eof', '', 34, 0]],
                'tree' => '11c1a64f24cbf436',
                'error' => null,
            ],
        ],
    ],
    'option turning x off, three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?-x)a #b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?-x)a #b)z)w', 0, 34], ['eof', '', 34, 0]],
                'tree' => '870b43a93fd36cf9',
                'error' => null,
            ],
        ],
    ],
    'option turning x off, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:(?-x)a #b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:(?-x)a #b)', 0, 22], ['eof', '', 22, 0]],
                'tree' => '7222fa67ff9ec7c9',
                'error' => null,
            ],
        ],
    ],
    'option turning x off, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:(?-x)a #b))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:(?-x)a #b)', 2, 23], ['literal', 'a', 25, 1], ['alternation', '|', 26, 1], ['literal', 'b', 27, 1], ['group_close', ')', 28, 1], ['eof', '', 29, 0]],
                'tree' => '070319920452eb9f',
                'error' => null,
            ],
        ],
    ],
    'option turning x off, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:(?-x)a #b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'option turning x off, two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:(?-x)a #b)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@22: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'option turning x off, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:(?-x)a #b/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'option turning x off, three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:(?-x)a #b/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'options unset, two deep' => [
        'pattern' => '/(*pla:(*nla:(?^)a #b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?^)a #b)', 0, 22], ['eof', '', 22, 0]],
                'tree' => '6e142d85008fd030',
                'error' => null,
            ],
        ],
    ],
    'options unset, two deep, under x' => [
        'pattern' => '/(*pla:(*nla:(?^)a #b))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?^)a #b)', 0, 22], ['eof', '', 22, 0]],
                'tree' => 'a048dec18de97b00',
                'error' => null,
            ],
        ],
    ],
    'options unset, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?^)a #b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?^)a #b)z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => '8f74fa4adeeb8f4a',
                'error' => null,
            ],
        ],
    ],
    'options unset, three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?^)a #b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?^)a #b)z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => 'f397ed170f0ccc3a',
                'error' => null,
            ],
        ],
    ],
    'line comment holding ")", two deep' => [
        'pattern' => "/(*pla:(*nla:a #c)\n b))/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*nla:a #c)\n b", 0, 21], ['group_close', ')', 21, 1], ['eof', '', 22, 0]],
                'tree' => 'regex.group.unmatched_close@21: Unmatched closing parenthesis at position 21.',
                'error' => 'regex.group.unmatched_close@21: Unmatched closing parenthesis at position 21.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*nla:a #c)\n b", 0, 21], ['group_close', ')', 21, 1], ['eof', '', 22, 0]],
                'tree' => 'regex.group.unmatched_close@22: Unmatched closing parenthesis at position 22.',
                'error' => 'regex.group.unmatched_close@22: Unmatched closing parenthesis at position 22.',
            ],
        ],
    ],
    'line comment holding ")", two deep, under x' => [
        'pattern' => "/(*pla:(*nla:a #c)\n b))/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a #c)\n b)", 0, 22], ['eof', '', 22, 0]],
                'tree' => '8ea08204e9ee7214',
                'error' => null,
            ],
        ],
    ],
    'line comment holding ")", three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a #c)\n b)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a #c)\n b)z", 0, 31], ['literal', 'w', 31, 1], ['group_close', ')', 32, 1], ['eof', '', 33, 0]],
                'tree' => 'regex.group.unmatched_close@32: Unmatched closing parenthesis at position 32.',
                'error' => 'regex.group.unmatched_close@32: Unmatched closing parenthesis at position 32.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a #c)\n b)z", 0, 31], ['literal', 'w', 31, 1], ['group_close', ')', 32, 1], ['eof', '', 33, 0]],
                'tree' => 'regex.group.unmatched_close@33: Unmatched closing parenthesis at position 33.',
                'error' => 'regex.group.unmatched_close@33: Unmatched closing parenthesis at position 33.',
            ],
        ],
    ],
    'line comment holding ")", three deep, under x' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a #c)\n b)z)w)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a #c)\n b)z)w", 0, 33], ['eof', '', 33, 0]],
                'tree' => 'b3fbd8b04cf259ef',
                'error' => null,
            ],
        ],
    ],
    'line comment holding ")", two deep in a script run' => [
        'pattern' => "/(*sr:(*plb:a #c)\n b))/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "sr:(*plb:a #c)\n b", 0, 20], ['group_close', ')', 20, 1], ['eof', '', 21, 0]],
                'tree' => 'regex.group.unmatched_close@20: Unmatched closing parenthesis at position 20.',
                'error' => 'regex.group.unmatched_close@20: Unmatched closing parenthesis at position 20.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "sr:(*plb:a #c)\n b", 0, 20], ['group_close', ')', 20, 1], ['eof', '', 21, 0]],
                'tree' => 'regex.group.unmatched_close@21: Unmatched closing parenthesis at position 21.',
                'error' => 'regex.group.unmatched_close@21: Unmatched closing parenthesis at position 21.',
            ],
        ],
    ],
    'line comment holding ")", as the condition, two deep' => [
        'pattern' => "/(?(*pla:(*pla:a #c)\n b))a|b)/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', "pla:(*pla:a #c)\n b", 2, 21], ['group_close', ')', 23, 1], ['literal', 'a', 24, 1], ['alternation', '|', 25, 1], ['literal', 'b', 26, 1], ['group_close', ')', 27, 1], ['eof', '', 28, 0]],
                'tree' => 'regex.group.unmatched_close@27: Unmatched closing parenthesis at position 27.',
                'error' => 'regex.group.unmatched_close@27: Unmatched closing parenthesis at position 27.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', "pla:(*pla:a #c)\n b", 2, 21], ['group_close', ')', 23, 1], ['literal', 'a', 24, 1], ['alternation', '|', 25, 1], ['literal', 'b', 26, 1], ['group_close', ')', 27, 1], ['eof', '', 28, 0]],
                'tree' => 'regex.group.unmatched_close@28: Unmatched closing parenthesis at position 28.',
                'error' => 'regex.group.unmatched_close@28: Unmatched closing parenthesis at position 28.',
            ],
        ],
    ],
    'line comment holding ")", two deep, the outer body left open' => [
        'pattern' => "/(*pla:(*nla:a #c)\n b)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a #c)\n b", 0, 21], ['eof', '', 21, 0]],
                'tree' => '8cebc69904c3fe28',
                'error' => null,
            ],
        ],
    ],
    'line comment holding ")", two deep, the outer body left open, under x' => [
        'pattern' => "/(*pla:(*nla:a #c)\n b)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'line comment holding ")", three deep, the bodies left open' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a #c)\n b/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'line comment holding ")", three deep, the bodies left open, under x' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a #c)\n b/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'inline x and its line comment, two deep' => [
        'pattern' => "/(*pla:(*nla:(?x)a #c)\n b))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:(?x)a #c)\n b)", 0, 26], ['eof', '', 26, 0]],
                'tree' => 'bb5470f57c4e8aab',
                'error' => null,
            ],
        ],
    ],
    'inline x and its line comment, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:(?x)a #c)\n b)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:(?x)a #c)\n b)z)w", 0, 37], ['eof', '', 37, 0]],
                'tree' => 'f3aae76cb6e25a13',
                'error' => null,
            ],
        ],
    ],
    'group under x and its line comment, two deep' => [
        'pattern' => "/(*pla:(*nla:(?x:a #c)\n )b))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:(?x:a #c)\n )b)", 0, 27], ['eof', '', 27, 0]],
                'tree' => '669856caa1fb1637',
                'error' => null,
            ],
        ],
    ],
    'group under x and its line comment, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:(?x:a #c)\n )b)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:(?x:a #c)\n )b)z)w", 0, 38], ['eof', '', 38, 0]],
                'tree' => '463e4a820480a11b',
                'error' => null,
            ],
        ],
    ],
    'class under xx, two deep' => [
        'pattern' => '/(*pla:(*nla:(?xx)[a )]b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?xx)[a )]b)', 0, 25], ['eof', '', 25, 0]],
                'tree' => 'c7425e2d85f78bd2',
                'error' => null,
            ],
        ],
    ],
    'class under xx, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?xx)[a )]b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?xx)[a )]b)z)w', 0, 36], ['eof', '', 36, 0]],
                'tree' => 'd339f85f8cb85983',
                'error' => null,
            ],
        ],
    ],
    'branch reset, two deep' => [
        'pattern' => "/(*pla:(*nla:(?|(a)|(b))\\1))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:(?|(a)|(b))\\1)", 0, 27], ['eof', '', 27, 0]],
                'tree' => '65c14f80b7048b54',
                'error' => null,
            ],
        ],
    ],
    'branch reset, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:(?|(a)|(b))\\1)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:(?|(a)|(b))\\1)z)w", 0, 38], ['eof', '', 38, 0]],
                'tree' => '8f267f1a00565b2b',
                'error' => null,
            ],
        ],
    ],
    'duplicate names, two deep' => [
        'pattern' => '/(*pla:(*nla:(?<n>a)(?<n>b)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?<n>a)(?<n>b))', 0, 28], ['eof', '', 28, 0]],
                'tree' => 'regex.group.duplicate_name@24: Duplicate group name "n" at position 24.',
                'error' => 'regex.group.duplicate_name@24: Duplicate group name "n" at position 24.',
            ],
        ],
    ],
    'duplicate names, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?<n>a)(?<n>b))z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?<n>a)(?<n>b))z)w', 0, 39], ['eof', '', 39, 0]],
                'tree' => 'regex.group.duplicate_name@32: Duplicate group name "n" at position 32.',
                'error' => 'regex.group.duplicate_name@32: Duplicate group name "n" at position 32.',
            ],
        ],
    ],
    'duplicate names, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:(?<n>a)(?<n>b)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'sr:(*plb:(?<n>a)(?<n>b))', 0, 27], ['eof', '', 27, 0]],
                'tree' => 'regex.group.duplicate_name@23: Duplicate group name "n" at position 23.',
                'error' => 'regex.group.duplicate_name@23: Duplicate group name "n" at position 23.',
            ],
        ],
    ],
    'duplicate names, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:(?<n>a)(?<n>b)))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:(?<n>a)(?<n>b))', 2, 28], ['literal', 'a', 30, 1], ['alternation', '|', 31, 1], ['literal', 'b', 32, 1], ['group_close', ')', 33, 1], ['eof', '', 34, 0]],
                'tree' => 'regex.group.duplicate_name@26: Duplicate group name "n" at position 26.',
                'error' => 'regex.group.duplicate_name@26: Duplicate group name "n" at position 26.',
            ],
        ],
    ],
    'duplicate names, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:(?<n>a)(?<n>b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@27: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@27: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.duplicate_name@24: Duplicate group name "n" at position 24.',
            ],
        ],
    ],
    'duplicate names, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:(?<n>a)(?<n>b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@35: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@35: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.duplicate_name@33: Duplicate group name "n" at position 33.',
            ],
        ],
    ],
    'duplicate names under J, two deep' => [
        'pattern' => '/(*pla:(*nla:(?J)(?<n>a)(?<n>b)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?J)(?<n>a)(?<n>b))', 0, 32], ['eof', '', 32, 0]],
                'tree' => '764d2aea041fd612',
                'error' => null,
            ],
        ],
    ],
    'duplicate names under J, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?J)(?<n>a)(?<n>b))z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?J)(?<n>a)(?<n>b))z)w', 0, 43], ['eof', '', 43, 0]],
                'tree' => '2aa581a1852f81ef',
                'error' => null,
            ],
        ],
    ],
    'reference to no group, two deep' => [
        'pattern' => "/(*pla:(*nla:a\\9))/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*nla:a\\9)", 0, 17], ['eof', '', 17, 0]],
                'tree' => '196cf9f52c678383',
                'error' => "regex.backref.missing_group@14: Backreference to non-existent group: \\9.",
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*nla:a\\9)", 0, 17], ['eof', '', 17, 0]],
                'tree' => '196cf9f52c678383',
                'error' => "regex.backref.missing_group@15: Backreference to non-existent group: \\9.",
            ],
        ],
    ],
    'reference to no group, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\9)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\\9)z)w", 0, 28], ['eof', '', 28, 0]],
                'tree' => 'd8795fe3183ae6df',
                'error' => "regex.backref.missing_group@22: Backreference to non-existent group: \\9.",
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\\9)z)w", 0, 28], ['eof', '', 28, 0]],
                'tree' => 'd8795fe3183ae6df',
                'error' => "regex.backref.missing_group@23: Backreference to non-existent group: \\9.",
            ],
        ],
    ],
    'call to no group, two deep' => [
        'pattern' => '/(*pla:(*nla:(?&nope)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?&nope))', 0, 22], ['eof', '', 22, 0]],
                'tree' => 'a0c3df2fefb31f97',
                'error' => 'regex.subroutine.missing_named_group@15: Subroutine call to non-existent named group: "nope".',
            ],
        ],
    ],
    'call to no group, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?&nope))z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?&nope))z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => '0e969c7a2b374d20',
                'error' => 'regex.subroutine.missing_named_group@23: Subroutine call to non-existent named group: "nope".',
            ],
        ],
    ],
    'recursion, two deep' => [
        'pattern' => '/(*pla:(*nla:a(?R)?))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a(?R)?)', 0, 20], ['eof', '', 20, 0]],
                'tree' => '7ff894d32f384b79',
                'error' => null,
            ],
        ],
    ],
    'recursion, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?R)?)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a(?R)?)z)w', 0, 31], ['eof', '', 31, 0]],
                'tree' => 'f333a1795017e411',
                'error' => null,
            ],
        ],
    ],
    'conditional on a lookahead, two deep' => [
        'pattern' => '/(*pla:(*nla:(?(?=a)b|c)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?(?=a)b|c))', 0, 25], ['eof', '', 25, 0]],
                'tree' => '99fa6ddd68d10856',
                'error' => null,
            ],
        ],
    ],
    'conditional on a lookahead, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?(?=a)b|c))z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?(?=a)b|c))z)w', 0, 36], ['eof', '', 36, 0]],
                'tree' => '01b5cc93eead1700',
                'error' => null,
            ],
        ],
    ],
    'conditional on an alphabetic lookahead, two deep' => [
        'pattern' => '/(*pla:(*nla:(?(*pla:a)b|c)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?(*pla:a)b|c))', 0, 28], ['eof', '', 28, 0]],
                'tree' => 'afe43ca6b3d53f29',
                'error' => null,
            ],
        ],
    ],
    'conditional on an alphabetic lookahead, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?(*pla:a)b|c))z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?(*pla:a)b|c))z)w', 0, 39], ['eof', '', 39, 0]],
                'tree' => '3e20f5ce3d5d81a6',
                'error' => null,
            ],
        ],
    ],
    'definition and its call, two deep' => [
        'pattern' => '/(*pla:(*nla:(?(DEFINE)(?<d>a))(?&d)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?(DEFINE)(?<d>a))(?&d))', 0, 37], ['eof', '', 37, 0]],
                'tree' => '64803dad7e2a47c2',
                'error' => null,
            ],
        ],
    ],
    'definition and its call, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?(DEFINE)(?<d>a))(?&d))z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?(DEFINE)(?<d>a))(?&d))z)w', 0, 48], ['eof', '', 48, 0]],
                'tree' => '96fef7ac8adc0e0c',
                'error' => null,
            ],
        ],
    ],
    'lookbehind, two deep' => [
        'pattern' => '/(*pla:(*nla:(?<=a)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?<=a)b)', 0, 21], ['eof', '', 21, 0]],
                'tree' => '92116f64f12a778f',
                'error' => null,
            ],
        ],
    ],
    'lookbehind, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?<=a)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?<=a)b)z)w', 0, 32], ['eof', '', 32, 0]],
                'tree' => '1a5040cf3f225da3',
                'error' => null,
            ],
        ],
    ],
    'script run, two deep' => [
        'pattern' => '/(*pla:(*nla:(*sr:ab)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(*sr:ab))', 0, 22], ['eof', '', 22, 0]],
                'tree' => 'cb1dc53695ff1ecb',
                'error' => null,
            ],
        ],
    ],
    'script run, two deep, under u' => [
        'pattern' => '/(*pla:(*nla:(*sr:ab)))/u',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(*sr:ab))', 0, 22], ['eof', '', 22, 0]],
                'tree' => 'b51eb34139c6d767',
                'error' => null,
            ],
        ],
    ],
    'script run, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(*sr:ab))z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(*sr:ab))z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => '00b82df50177dcdb',
                'error' => null,
            ],
        ],
    ],
    'script run, three deep, under u' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(*sr:ab))z)w)/u',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(*sr:ab))z)w', 0, 33], ['eof', '', 33, 0]],
                'tree' => '1cf2c1ecd9078a17',
                'error' => null,
            ],
        ],
    ],
    'script run, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:(*sr:ab)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'sr:(*plb:(*sr:ab))', 0, 21], ['eof', '', 21, 0]],
                'tree' => '5dbf2e4dbec51827',
                'error' => null,
            ],
        ],
    ],
    'script run, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:(*sr:ab)))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:(*sr:ab))', 2, 22], ['literal', 'a', 24, 1], ['alternation', '|', 25, 1], ['literal', 'b', 26, 1], ['group_close', ')', 27, 1], ['eof', '', 28, 0]],
                'tree' => '15e82d6ea0b1dad2',
                'error' => null,
            ],
        ],
    ],
    'script run, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:(*sr:ab))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@21: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'script run, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:(*sr:ab)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'atomic script run, two deep' => [
        'pattern' => '/(*pla:(*nla:(*asr:ab)c))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(*asr:ab)c)', 0, 24], ['eof', '', 24, 0]],
                'tree' => 'f29ab048276585cd',
                'error' => null,
            ],
        ],
    ],
    'atomic script run, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(*asr:ab)c)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(*asr:ab)c)z)w', 0, 35], ['eof', '', 35, 0]],
                'tree' => '0c2bea9376d62e09',
                'error' => null,
            ],
        ],
    ],
    'short non-atomic lookahead, two deep' => [
        'pattern' => '/(*pla:(*nla:(?*a)b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?*a)b)', 0, 20], ['eof', '', 20, 0]],
                'tree' => '41e20f1aa410b507',
                'error' => null,
            ],
        ],
    ],
    'short non-atomic lookahead, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?*a)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?*a)b)z)w', 0, 31], ['eof', '', 31, 0]],
                'tree' => '17d7f8dd27ddac57',
                'error' => null,
            ],
        ],
    ],
    'extended class, two deep' => [
        'pattern' => '/(*pla:(*nla:(?[[a]+[)]])b))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?[[a]+[)]])b)', 0, 27], ['eof', '', 27, 0]],
                'tree' => 'regex.group.syntax@14: Invalid group modifier syntax at position 14.',
                'error' => 'regex.group.syntax@14: Invalid group modifier syntax at position 14.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:(?[[a]+[)]])b)', 0, 27], ['eof', '', 27, 0]],
                'tree' => '9f4a8a3d7878db6f',
                'error' => null,
            ],
        ],
    ],
    'extended class, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(?[[a]+[)]])b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?[[a]+[)]])b)z)w', 0, 38], ['eof', '', 38, 0]],
                'tree' => 'regex.group.syntax@22: Invalid group modifier syntax at position 22.',
                'error' => 'regex.group.syntax@22: Invalid group modifier syntax at position 22.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(?[[a]+[)]])b)z)w', 0, 38], ['eof', '', 38, 0]],
                'tree' => '47dc2fb375ff81ca',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, two deep' => [
        'pattern' => "/(*pla:(*nla:\xC3\xA9))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\xC3\xA9)", 0, 16], ['eof', '', 16, 0]],
                'tree' => '8c00cfc4cff17dcf',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, two deep, under x' => [
        'pattern' => "/(*pla:(*nla:\xC3\xA9))/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\xC3\xA9)", 0, 16], ['eof', '', 16, 0]],
                'tree' => '0fb61a45417395f6',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, two deep, under u' => [
        'pattern' => "/(*pla:(*nla:\xC3\xA9))/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\xC3\xA9)", 0, 16], ['eof', '', 16, 0]],
                'tree' => '0f16e5da3313e197',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\xC3\xA9)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\xC3\xA9)z)w", 0, 27], ['eof', '', 27, 0]],
                'tree' => '92fd2ea07f2ba8fa',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, three deep, under x' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\xC3\xA9)z)w)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\xC3\xA9)z)w", 0, 27], ['eof', '', 27, 0]],
                'tree' => '937a394abc1d8481',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, three deep, under u' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\xC3\xA9)z)w)/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\xC3\xA9)z)w", 0, 27], ['eof', '', 27, 0]],
                'tree' => 'd687ea3f7e13c967',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, two deep in a script run' => [
        'pattern' => "/(*sr:(*plb:\xC3\xA9))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "sr:(*plb:\xC3\xA9)", 0, 15], ['eof', '', 15, 0]],
                'tree' => 'add35b5fc12daf38',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, as the condition, two deep' => [
        'pattern' => "/(?(*pla:(*pla:\xC3\xA9))a|b)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', "pla:(*pla:\xC3\xA9)", 2, 16], ['literal', 'a', 18, 1], ['alternation', '|', 19, 1], ['literal', 'b', 20, 1], ['group_close', ')', 21, 1], ['eof', '', 22, 0]],
                'tree' => 'afaf7207898db73f',
                'error' => null,
            ],
        ],
    ],
    'multibyte character, two deep, the outer body left open' => [
        'pattern' => "/(*pla:(*nla:\xC3\xA9)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@15: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@15: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@15: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'multibyte character, two deep, the outer body left open, under x' => [
        'pattern' => "/(*pla:(*nla:\xC3\xA9)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@15: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@15: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@15: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'multibyte character, three deep, the bodies left open' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:\xC3\xA9/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'multibyte character, three deep, the bodies left open, under x' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:\xC3\xA9/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'property, two deep' => [
        'pattern' => "/(*pla:(*nla:\\p{L}))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\\p{L})", 0, 19], ['eof', '', 19, 0]],
                'tree' => 'ed06deb064d37b3c',
                'error' => null,
            ],
        ],
    ],
    'property, two deep, under u' => [
        'pattern' => "/(*pla:(*nla:\\p{L}))/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\\p{L})", 0, 19], ['eof', '', 19, 0]],
                'tree' => '846fa78050465923',
                'error' => null,
            ],
        ],
    ],
    'property, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\\p{L})z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\\p{L})z)w", 0, 30], ['eof', '', 30, 0]],
                'tree' => 'a9e7a9a81b9f0a4f',
                'error' => null,
            ],
        ],
    ],
    'property, three deep, under u' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\\p{L})z)w)/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\\p{L})z)w", 0, 30], ['eof', '', 30, 0]],
                'tree' => 'a15617d2bcb34220',
                'error' => null,
            ],
        ],
    ],
    'property never closed, two deep' => [
        'pattern' => "/(*pla:(*nla:\\p{L))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*nla:\\p{L)", 0, 18], ['eof', '', 18, 0]],
                'tree' => '385e67f92928c1e8',
                'error' => "regex.unicode.property_malformed@18: Malformed \\p sequence: a property letter or a braced name must follow it.",
            ],
        ],
    ],
    'property never closed, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\\p{L)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\\p{L)z)w", 0, 29], ['eof', '', 29, 0]],
                'tree' => 'ef6d7a6bf4bef98c',
                'error' => "regex.unicode.property_malformed@29: Malformed \\p sequence: a property letter or a braced name must follow it.",
            ],
        ],
    ],
    'property PCRE does not know, two deep' => [
        'pattern' => "/(*pla:(*nla:\\p{Foo}))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*nla:\\p{Foo})", 0, 21], ['eof', '', 21, 0]],
                'tree' => '2398043e73342999',
                'error' => "regex.unicode.property_invalid@19: Invalid or unsupported Unicode property: \\p{Foo}.",
            ],
        ],
    ],
    'property PCRE does not know, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\\p{Foo})z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\\p{Foo})z)w", 0, 32], ['eof', '', 32, 0]],
                'tree' => '9e17806bfb61f660',
                'error' => "regex.unicode.property_invalid@27: Invalid or unsupported Unicode property: \\p{Foo}.",
            ],
        ],
    ],
    'hex escape, two deep' => [
        'pattern' => "/(*pla:(*nla:\\x{41}))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\\x{41})", 0, 20], ['eof', '', 20, 0]],
                'tree' => '677ca950da75824c',
                'error' => null,
            ],
        ],
    ],
    'hex escape, two deep, under u' => [
        'pattern' => "/(*pla:(*nla:\\x{41}))/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:\\x{41})", 0, 20], ['eof', '', 20, 0]],
                'tree' => '474e3e6e2e795a58',
                'error' => null,
            ],
        ],
    ],
    'hex escape, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\\x{41})z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\\x{41})z)w", 0, 31], ['eof', '', 31, 0]],
                'tree' => '500157061fab1aa7',
                'error' => null,
            ],
        ],
    ],
    'hex escape, three deep, under u' => [
        'pattern' => "/(*pla:x(?*y(*atomic:\\x{41})z)w)/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:\\x{41})z)w", 0, 31], ['eof', '', 31, 0]],
                'tree' => '8ff00912ed2d31d8',
                'error' => null,
            ],
        ],
    ],
    'hex escape without digits, two deep' => [
        'pattern' => "/(*pla:(*nla:a\\x{}))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*nla:a\\x{})", 0, 19], ['eof', '', 19, 0]],
                'tree' => "regex.escape.digits_missing@16: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => "regex.escape.digits_missing@16: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'hex escape without digits, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\x{})z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\\x{})z)w", 0, 30], ['eof', '', 30, 0]],
                'tree' => "regex.escape.digits_missing@24: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => "regex.escape.digits_missing@24: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'hex escape without digits, two deep in a script run' => [
        'pattern' => "/(*sr:(*plb:a\\x{}))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "sr:(*plb:a\\x{})", 0, 18], ['eof', '', 18, 0]],
                'tree' => "regex.escape.digits_missing@15: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => "regex.escape.digits_missing@15: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'hex escape without digits, as the condition, two deep' => [
        'pattern' => "/(?(*pla:(*pla:a\\x{}))a|b)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', "pla:(*pla:a\\x{})", 2, 19], ['literal', 'a', 21, 1], ['alternation', '|', 22, 1], ['literal', 'b', 23, 1], ['group_close', ')', 24, 1], ['eof', '', 25, 0]],
                'tree' => "regex.escape.digits_missing@18: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => "regex.escape.digits_missing@18: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'hex escape without digits, two deep, the outer body left open' => [
        'pattern' => "/(*pla:(*nla:a\\x{})/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'error' => "regex.escape.digits_missing@16: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'hex escape without digits, three deep, the bodies left open' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a\\x{}/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => "regex.escape.digits_missing@25: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'tree' => "regex.escape.digits_missing@25: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => "regex.escape.digits_missing@25: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'control escape taking ")", two deep' => [
        'pattern' => "/(*pla:(*nla:a\\c)b))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a\\c)b)", 0, 19], ['eof', '', 19, 0]],
                'tree' => '62228353dc24c5b2',
                'error' => null,
            ],
        ],
    ],
    'control escape taking ")", three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\c)b)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\\c)b)z)w", 0, 30], ['eof', '', 30, 0]],
                'tree' => '852c26f1c787c5d8',
                'error' => null,
            ],
        ],
    ],
    'byte that is no UTF-8, two deep' => [
        'pattern' => "/(*pla:(*nla:a\xFFb))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a\xFFb)", 0, 17], ['eof', '', 17, 0]],
                'tree' => '94597686bee665aa',
                'error' => null,
            ],
        ],
    ],
    'byte that is no UTF-8, two deep, under x' => [
        'pattern' => "/(*pla:(*nla:a\xFFb))/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*nla:a\xFFb)", 0, 17], ['eof', '', 17, 0]],
                'tree' => '3f805d5e30575c45',
                'error' => null,
            ],
        ],
    ],
    'byte that is no UTF-8, two deep, under u' => [
        'pattern' => "/(*pla:(*nla:a\xFFb))/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.encoding.invalid_utf8@13: Input string is not valid UTF-8.',
                'tree' => 'regex.encoding.invalid_utf8@13: Input string is not valid UTF-8.',
                'error' => 'regex.encoding.invalid_utf8@13: Input string is not valid UTF-8.',
            ],
        ],
    ],
    'byte that is no UTF-8, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\xFFb)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\xFFb)z)w", 0, 28], ['eof', '', 28, 0]],
                'tree' => 'f9d45cb2fb568f9c',
                'error' => null,
            ],
        ],
    ],
    'byte that is no UTF-8, three deep, under x' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\xFFb)z)w)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:x(?*y(*atomic:a\xFFb)z)w", 0, 28], ['eof', '', 28, 0]],
                'tree' => '81134b17fcc6a2b6',
                'error' => null,
            ],
        ],
    ],
    'byte that is no UTF-8, three deep, under u' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\xFFb)z)w)/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.encoding.invalid_utf8@21: Input string is not valid UTF-8.',
                'tree' => 'regex.encoding.invalid_utf8@21: Input string is not valid UTF-8.',
                'error' => 'regex.encoding.invalid_utf8@21: Input string is not valid UTF-8.',
            ],
        ],
    ],
    'byte that is no UTF-8, two deep in a script run' => [
        'pattern' => "/(*sr:(*plb:a\xFFb))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "sr:(*plb:a\xFFb)", 0, 16], ['eof', '', 16, 0]],
                'tree' => 'decdb4a18825eae8',
                'error' => null,
            ],
        ],
    ],
    'byte that is no UTF-8, as the condition, two deep' => [
        'pattern' => "/(?(*pla:(*pla:a\xFFb))a|b)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', "pla:(*pla:a\xFFb)", 2, 17], ['literal', 'a', 19, 1], ['alternation', '|', 20, 1], ['literal', 'b', 21, 1], ['group_close', ')', 22, 1], ['eof', '', 23, 0]],
                'tree' => '9eebfdac416d0268',
                'error' => null,
            ],
        ],
    ],
    'byte that is no UTF-8, two deep, the outer body left open' => [
        'pattern' => "/(*pla:(*nla:a\xFFb)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'byte that is no UTF-8, two deep, the outer body left open, under x' => [
        'pattern' => "/(*pla:(*nla:a\xFFb)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'byte that is no UTF-8, three deep, the bodies left open' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a\xFFb/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'byte that is no UTF-8, three deep, the bodies left open, under x' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a\xFFb/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quantifiers, two deep' => [
        'pattern' => '/(*pla:(*nla:a{2}b+?c*+))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a{2}b+?c*+)', 0, 24], ['eof', '', 24, 0]],
                'tree' => 'b0b3d41aebf7b536',
                'error' => null,
            ],
        ],
    ],
    'quantifiers, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a{2}b+?c*+)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a{2}b+?c*+)z)w', 0, 35], ['eof', '', 35, 0]],
                'tree' => '84389a5d42431d54',
                'error' => null,
            ],
        ],
    ],
    'quantifier on nothing, two deep' => [
        'pattern' => '/(*pla:(*nla:a|*))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a|*)', 0, 17], ['eof', '', 17, 0]],
                'tree' => 'regex.quantifier.nothing_to_repeat@14: Quantifier without target at position 14.',
                'error' => 'regex.quantifier.nothing_to_repeat@14: Quantifier without target at position 14.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a|*)', 0, 17], ['eof', '', 17, 0]],
                'tree' => 'regex.quantifier.nothing_to_repeat@15: Quantifier without target at position 15.',
                'error' => 'regex.quantifier.nothing_to_repeat@15: Quantifier without target at position 15.',
            ],
        ],
    ],
    'quantifier on nothing, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a|*)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a|*)z)w', 0, 28], ['eof', '', 28, 0]],
                'tree' => 'regex.quantifier.nothing_to_repeat@22: Quantifier without target at position 22.',
                'error' => 'regex.quantifier.nothing_to_repeat@22: Quantifier without target at position 22.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a|*)z)w', 0, 28], ['eof', '', 28, 0]],
                'tree' => 'regex.quantifier.nothing_to_repeat@23: Quantifier without target at position 23.',
                'error' => 'regex.quantifier.nothing_to_repeat@23: Quantifier without target at position 23.',
            ],
        ],
    ],
    'counts out of order, two deep' => [
        'pattern' => '/(*pla:(*nla:a{3,1}))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a{3,1})', 0, 20], ['eof', '', 20, 0]],
                'tree' => '864aef1fd475484b',
                'error' => 'regex.quantifier.invalid_range@17: Invalid quantifier range "{3,1}": min > max.',
            ],
        ],
    ],
    'counts out of order, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a{3,1})z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a{3,1})z)w', 0, 31], ['eof', '', 31, 0]],
                'tree' => 'f4b1d2cb4e7cfbf7',
                'error' => 'regex.quantifier.invalid_range@25: Invalid quantifier range "{3,1}": min > max.',
            ],
        ],
    ],
    'range out of order, two deep' => [
        'pattern' => '/(*pla:(*nla:[z-a]))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:[z-a])', 0, 19], ['eof', '', 19, 0]],
                'tree' => 'f7b135901980aef1',
                'error' => 'regex.range.reversed@15: Invalid range "z-a": start character comes after end character.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:[z-a])', 0, 19], ['eof', '', 19, 0]],
                'tree' => 'f7b135901980aef1',
                'error' => 'regex.range.reversed@16: Invalid range "z-a": start character comes after end character.',
            ],
        ],
    ],
    'range out of order, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:[z-a])z)w)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[z-a])z)w', 0, 30], ['eof', '', 30, 0]],
                'tree' => '8aa6e27c25840ca0',
                'error' => 'regex.range.reversed@23: Invalid range "z-a": start character comes after end character.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:[z-a])z)w', 0, 30], ['eof', '', 30, 0]],
                'tree' => '8aa6e27c25840ca0',
                'error' => 'regex.range.reversed@24: Invalid range "z-a": start character comes after end character.',
            ],
        ],
    ],
    'start option, two deep' => [
        'pattern' => '/(*pla:(*nla:(*UTF)a))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:(*UTF)a)', 0, 21], ['eof', '', 21, 0]],
                'tree' => 'b3490ff7b435ae6e',
                'error' => 'regex.verb.misplaced@17: (*UTF) is only recognized at the very start of the pattern.',
            ],
        ],
    ],
    'start option, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:(*UTF)a)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:(*UTF)a)z)w', 0, 32], ['eof', '', 32, 0]],
                'tree' => 'b0f3442e9de2e213',
                'error' => 'regex.verb.misplaced@25: (*UTF) is only recognized at the very start of the pattern.',
            ],
        ],
    ],
    'one ")" too many, two deep' => [
        'pattern' => '/(*pla:(*nla:a)b))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)b', 0, 16], ['group_close', ')', 16, 1], ['eof', '', 17, 0]],
                'tree' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
                'error' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)b', 0, 16], ['group_close', ')', 16, 1], ['eof', '', 17, 0]],
                'tree' => 'regex.group.unmatched_close@17: Unmatched closing parenthesis at position 17.',
                'error' => 'regex.group.unmatched_close@17: Unmatched closing parenthesis at position 17.',
            ],
        ],
    ],
    'one ")" too many, two deep, under x' => [
        'pattern' => '/(*pla:(*nla:a)b))/x',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)b', 0, 16], ['group_close', ')', 16, 1], ['eof', '', 17, 0]],
                'tree' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
                'error' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)b', 0, 16], ['group_close', ')', 16, 1], ['eof', '', 17, 0]],
                'tree' => 'regex.group.unmatched_close@17: Unmatched closing parenthesis at position 17.',
                'error' => 'regex.group.unmatched_close@17: Unmatched closing parenthesis at position 17.',
            ],
        ],
    ],
    'one ")" too many, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a)b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a)b)z', 0, 26], ['literal', 'w', 26, 1], ['group_close', ')', 27, 1], ['eof', '', 28, 0]],
                'tree' => 'regex.group.unmatched_close@27: Unmatched closing parenthesis at position 27.',
                'error' => 'regex.group.unmatched_close@27: Unmatched closing parenthesis at position 27.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a)b)z', 0, 26], ['literal', 'w', 26, 1], ['group_close', ')', 27, 1], ['eof', '', 28, 0]],
                'tree' => 'regex.group.unmatched_close@28: Unmatched closing parenthesis at position 28.',
                'error' => 'regex.group.unmatched_close@28: Unmatched closing parenthesis at position 28.',
            ],
        ],
    ],
    'one ")" too many, three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a)b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a)b)z', 0, 26], ['literal', 'w', 26, 1], ['group_close', ')', 27, 1], ['eof', '', 28, 0]],
                'tree' => 'regex.group.unmatched_close@27: Unmatched closing parenthesis at position 27.',
                'error' => 'regex.group.unmatched_close@27: Unmatched closing parenthesis at position 27.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:x(?*y(*atomic:a)b)z', 0, 26], ['literal', 'w', 26, 1], ['group_close', ')', 27, 1], ['eof', '', 28, 0]],
                'tree' => 'regex.group.unmatched_close@28: Unmatched closing parenthesis at position 28.',
                'error' => 'regex.group.unmatched_close@28: Unmatched closing parenthesis at position 28.',
            ],
        ],
    ],
    'one ")" too many, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:a)b))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'sr:(*plb:a)b', 0, 15], ['group_close', ')', 15, 1], ['eof', '', 16, 0]],
                'tree' => 'regex.group.unmatched_close@15: Unmatched closing parenthesis at position 15.',
                'error' => 'regex.group.unmatched_close@15: Unmatched closing parenthesis at position 15.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'sr:(*plb:a)b', 0, 15], ['group_close', ')', 15, 1], ['eof', '', 16, 0]],
                'tree' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
                'error' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
            ],
        ],
    ],
    'one ")" too many, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:a)b))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:a)b', 2, 16], ['group_close', ')', 18, 1], ['literal', 'a', 19, 1], ['alternation', '|', 20, 1], ['literal', 'b', 21, 1], ['group_close', ')', 22, 1], ['eof', '', 23, 0]],
                'tree' => 'regex.group.unmatched_close@22: Unmatched closing parenthesis at position 22.',
                'error' => 'regex.group.unmatched_close@22: Unmatched closing parenthesis at position 22.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:a)b', 2, 16], ['group_close', ')', 18, 1], ['literal', 'a', 19, 1], ['alternation', '|', 20, 1], ['literal', 'b', 21, 1], ['group_close', ')', 22, 1], ['eof', '', 23, 0]],
                'tree' => 'regex.group.unmatched_close@23: Unmatched closing parenthesis at position 23.',
                'error' => 'regex.group.unmatched_close@23: Unmatched closing parenthesis at position 23.',
            ],
        ],
    ],
    'one ")" too many, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:a)b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)b', 0, 16], ['eof', '', 16, 0]],
                'tree' => '81cb3e745c510cb9',
                'error' => null,
            ],
        ],
    ],
    'one ")" too many, two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:a)b)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*nla:a)b', 0, 16], ['eof', '', 16, 0]],
                'tree' => '6bba66326e803dfd',
                'error' => null,
            ],
        ],
    ],
    'one ")" too many, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a)b/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'one ")" too many, three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a)b/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'backslash before the ")", two deep' => [
        'pattern' => "/(*pla:(*nla:a\\))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@16: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'backslash before the ")", three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@27: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@27: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@27: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment never closed, two deep' => [
        'pattern' => '/(*pla:(*nla:a(?#b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@19: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@19: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@19: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment never closed, two deep, under x' => [
        'pattern' => '/(*pla:(*nla:a(?#b))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@19: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@19: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@19: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment never closed, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?#b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment never closed, three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?#b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@30: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment never closed, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:a(?#b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*sr:".',
                'tree' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*sr:".',
                'error' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*sr:".',
            ],
        ],
    ],
    'comment never closed, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:a(?#b))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['pcre_verb', 'pla:(*pla:a(?#b))a|b', 2, 23], ['eof', '', 25, 0]],
                'tree' => 'regex.group.unclosed@25: Expected ) at end of input (found eof).',
                'error' => 'regex.group.unclosed@25: Expected ) at end of input (found eof).',
            ],
        ],
    ],
    'comment never closed, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:a(?#b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment never closed, two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:a(?#b)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'comment never closed, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(?#b/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.comment.unclosed@26: Unclosed comment ")" at end of input.',
                'tree' => 'regex.comment.unclosed@26: Unclosed comment ")" at end of input.',
                'error' => 'regex.comment.unclosed@26: Unclosed comment ")" at end of input.',
            ],
        ],
    ],
    'comment never closed, three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a(?#b/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.comment.unclosed@26: Unclosed comment ")" at end of input.',
                'tree' => 'regex.comment.unclosed@26: Unclosed comment ")" at end of input.',
                'error' => 'regex.comment.unclosed@26: Unclosed comment ")" at end of input.',
            ],
        ],
    ],
    'class never closed, two deep' => [
        'pattern' => '/(*pla:(*nla:a[b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@17: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@17: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@17: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, two deep, under x' => [
        'pattern' => '/(*pla:(*nla:a[b))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@17: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@17: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@17: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a[b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@28: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@28: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@28: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, three deep, under x' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a[b)z)w)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@28: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@28: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@28: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, two deep in a script run' => [
        'pattern' => '/(*sr:(*plb:a[b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, as the condition, two deep' => [
        'pattern' => '/(?(*pla:(*pla:a[b))a|b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@23: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@23: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@23: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, two deep, the outer body left open' => [
        'pattern' => '/(*pla:(*nla:a[b)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, two deep, the outer body left open, under x' => [
        'pattern' => '/(*pla:(*nla:a[b)/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@16: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, three deep, the bodies left open' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a[b/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@24: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@24: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@24: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'class never closed, three deep, the bodies left open, under x' => [
        'pattern' => '/(*pla:(*nlb:(*atomic:a[b/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@24: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@24: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@24: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'quoted run never ended, two deep' => [
        'pattern' => "/(*pla:(*nla:a\\Qb))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, two deep, under x' => [
        'pattern' => "/(*pla:(*nla:a\\Qb))/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@18: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, three deep' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\Qb)z)w)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, three deep, under x' => [
        'pattern' => "/(*pla:x(?*y(*atomic:a\\Qb)z)w)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@29: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, two deep in a script run' => [
        'pattern' => "/(*sr:(*plb:a\\Qb))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*sr:".',
                'tree' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*sr:".',
                'error' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*sr:".',
            ],
        ],
    ],
    'quoted run never ended, as the condition, two deep' => [
        'pattern' => "/(?(*pla:(*pla:a\\Qb))a|b)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@24: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, two deep, the outer body left open' => [
        'pattern' => "/(*pla:(*nla:a\\Qb)/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, two deep, the outer body left open, under x' => [
        'pattern' => "/(*pla:(*nla:a\\Qb)/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, three deep, the bodies left open' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a\\Qb/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'quoted run never ended, three deep, the bodies left open, under x' => [
        'pattern' => "/(*pla:(*nlb:(*atomic:a\\Qb/x",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@25: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'group never closed, two deep' => [
        'pattern' => '/(*pla:(*nla:a(b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@17: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'group never closed, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@28: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'callout string never closed, two deep' => [
        'pattern' => '/(*pla:(*nla:a(?C"b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@20: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.callout.unclosed_string@16: Invalid callout argument: "b at position 16: the callout string is not closed by its delimiter.',
            ],
        ],
    ],
    'callout string never closed, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(?C"b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@31: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@31: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.callout.unclosed_string@24: Invalid callout argument: "b at position 24: the callout string is not closed by its delimiter.',
            ],
        ],
    ],
    'verb never closed, two deep' => [
        'pattern' => '/(*pla:(*nla:a(*MARK:b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@23: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'verb never closed, three deep' => [
        'pattern' => '/(*pla:x(?*y(*atomic:a(*MARK:b)z)w)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
                'tree' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
                'error' => 'regex.group.unclosed@34: Missing closing parenthesis for "(*pla:".',
            ],
        ],
    ],
    'groups numbered across bodies' => [
        'pattern' => "/(a)(*pla:(b)(*pla:(c)\\3)\\2)\\1/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_open', '(', 0, 1], ['literal', 'a', 1, 1], ['group_close', ')', 2, 1], ['pcre_verb', "pla:(b)(*pla:(c)\\3)\\2", 3, 24], ['backref', "\\1", 27, 2], ['eof', '', 29, 0]],
                'tree' => '1b048584066c2064',
                'error' => null,
            ],
        ],
    ],
    'reference past the groups of the bodies' => [
        'pattern' => "/(a)(*pla:(b)(*pla:(c)\\4))/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['group_open', '(', 0, 1], ['literal', 'a', 1, 1], ['group_close', ')', 2, 1], ['pcre_verb', "pla:(b)(*pla:(c)\\4)", 3, 22], ['eof', '', 25, 0]],
                'tree' => '8e224aca9ab21277',
                'error' => "regex.backref.missing_group@22: Backreference to non-existent group: \\4.",
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['group_open', '(', 0, 1], ['literal', 'a', 1, 1], ['group_close', ')', 2, 1], ['pcre_verb', "pla:(b)(*pla:(c)\\4)", 3, 22], ['eof', '', 25, 0]],
                'tree' => '8e224aca9ab21277',
                'error' => "regex.backref.missing_group@23: Backreference to non-existent group: \\4.",
            ],
        ],
    ],
    'name of the pattern read in a body two deep' => [
        'pattern' => "/(?<n>a)(*pla:(*pla:\\k<n>))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['literal', '<', 2, 1], ['literal', 'n', 3, 1], ['literal', '>', 4, 1], ['literal', 'a', 5, 1], ['group_close', ')', 6, 1], ['pcre_verb', "pla:(*pla:\\k<n>)", 7, 19], ['eof', '', 26, 0]],
                'tree' => '989fa5d326bf2672',
                'error' => null,
            ],
        ],
    ],
    'name of a body two deep read after it' => [
        'pattern' => "/(*pla:(*pla:(?<n>a)))\\k<n>/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*pla:(?<n>a))', 0, 21], ['backref', "\\k<n>", 21, 5], ['eof', '', 26, 0]],
                'tree' => '1b16500287b42cb9',
                'error' => null,
            ],
        ],
    ],
    'name given twice, in the pattern and two deep' => [
        'pattern' => '/(?<n>a)(*pla:(*pla:(?<n>b)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['literal', '<', 2, 1], ['literal', 'n', 3, 1], ['literal', '>', 4, 1], ['literal', 'a', 5, 1], ['group_close', ')', 6, 1], ['pcre_verb', 'pla:(*pla:(?<n>b))', 7, 21], ['eof', '', 28, 0]],
                'tree' => 'regex.group.duplicate_name@24: Duplicate group name "n" at position 24.',
                'error' => 'regex.group.duplicate_name@24: Duplicate group name "n" at position 24.',
            ],
        ],
    ],
    'name given twice under J set two deep' => [
        'pattern' => '/(?<n>a)(*pla:(*pla:(?J)(?<n>b)))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['group_modifier_open', '(?', 0, 2], ['literal', '<', 2, 1], ['literal', 'n', 3, 1], ['literal', '>', 4, 1], ['literal', 'a', 5, 1], ['group_close', ')', 6, 1], ['pcre_verb', 'pla:(*pla:(?J)(?<n>b))', 7, 25], ['eof', '', 32, 0]],
                'tree' => '9aa846322508c9ee',
                'error' => null,
            ],
        ],
    ],
    'recursion test before the group it names, two deep' => [
        'pattern' => '/(*pla:(*pla:(?(R2)b|c)))(?<R2>a)/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*pla:(?(R2)b|c))', 0, 24], ['group_modifier_open', '(?', 24, 2], ['literal', '<', 26, 1], ['literal', 'R', 27, 1], ['literal', '2', 28, 1], ['literal', '>', 29, 1], ['literal', 'a', 30, 1], ['group_close', ')', 31, 1], ['eof', '', 32, 0]],
                'tree' => '5267c9695c20c06e',
                'error' => null,
            ],
        ],
    ],
    'x set in a body holds in the bodies inside it' => [
        'pattern' => "/(*pla:(?x) a (*pla: b #c)\n ) )/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(?x) a (*pla: b #c)\n ) ", 0, 30], ['eof', '', 30, 0]],
                'tree' => 'ce3433868c7a5b46',
                'error' => null,
            ],
        ],
    ],
    'x turned off in a body holds in the bodies inside it, under x' => [
        'pattern' => '/(*pla:(?-x)a(*pla: b #c))/x',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(?-x)a(*pla: b #c)', 0, 25], ['eof', '', 25, 0]],
                'tree' => '5dfd2ab275a8568c',
                'error' => null,
            ],
        ],
    ],
    'xx set in a body holds in the bodies inside it' => [
        'pattern' => '/(*pla:(?xx)(*pla:[a )]b))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(?xx)(*pla:[a )]b)', 0, 25], ['eof', '', 25, 0]],
                'tree' => 'c21509f0ac79e131',
                'error' => null,
            ],
        ],
    ],
    'n set in a body holds in the bodies inside it' => [
        'pattern' => "/(*pla:(?n)(*pla:(a)(?<m>b)))\\1/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(?n)(*pla:(a)(?<m>b))', 0, 28], ['backref', "\\1", 28, 2], ['eof', '', 30, 0]],
                'tree' => 'b2be95f23b548163',
                'error' => null,
            ],
        ],
    ],
    'substring scan two deep' => [
        'pattern' => '/(a)(*pla:(*scs:(1)b(*nla:c)))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['group_open', '(', 0, 1], ['literal', 'a', 1, 1], ['group_close', ')', 2, 1], ['pcre_verb', 'pla:(*scs:(1)b(*nla:c))', 3, 26], ['eof', '', 29, 0]],
                'tree' => '9f9eadb817fa753b',
                'error' => 'regex.verb.invalid@14: Invalid or unsupported PCRE verb: "scs".',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => true,
                'tokens' => [['group_open', '(', 0, 1], ['literal', 'a', 1, 1], ['group_close', ')', 2, 1], ['pcre_verb', 'pla:(*scs:(1)b(*nla:c))', 3, 26], ['eof', '', 29, 0]],
                'tree' => 'b2dece358a27d2c8',
                'error' => null,
            ],
        ],
    ],
    'substring scan around two bodies' => [
        'pattern' => '/(a)(*scs:(1)(*pla:(*pla:b)c))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['group_open', '(', 0, 1], ['literal', 'a', 1, 1], ['group_close', ')', 2, 1], ['pcre_verb', 'scs:(1)(*pla:(*pla:b)c)', 3, 26], ['eof', '', 29, 0]],
                'tree' => '86aff3807f675640',
                'error' => 'regex.verb.invalid@8: Invalid or unsupported PCRE verb: "scs".',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => true,
                'tokens' => [['group_open', '(', 0, 1], ['literal', 'a', 1, 1], ['group_close', ')', 2, 1], ['pcre_verb', 'scs:(1)(*pla:(*pla:b)c)', 3, 26], ['eof', '', 29, 0]],
                'tree' => '82fea6216212d06f',
                'error' => null,
            ],
        ],
    ],
    'substring scan naming no group, two deep' => [
        'pattern' => '/(*pla:(*scs:(2)(*pla:a)))/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*scs:(2)(*pla:a))', 0, 25], ['eof', '', 25, 0]],
                'tree' => 'c3b0ae023a16b3d2',
                'error' => 'regex.verb.invalid@11: Invalid or unsupported PCRE verb: "scs".',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*scs:(2)(*pla:a))', 0, 25], ['eof', '', 25, 0]],
                'tree' => 'e2012bff6cdf24fe',
                'error' => 'regex.group_list.missing_group@13: Group 2 is listed but does not exist.',
            ],
        ],
    ],
    'bodies side by side in a body' => [
        'pattern' => '/(*pla:(*pla:a)(*nla:b)|(*atomic:c))d/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*pla:a)(*nla:b)|(*atomic:c)', 0, 35], ['literal', 'd', 35, 1], ['eof', '', 36, 0]],
                'tree' => '6e5825a000987465',
                'error' => null,
            ],
        ],
    ],
    'quantified body two deep' => [
        'pattern' => '/(*pla:(*atomic:a)+(*sr:b){2})/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*atomic:a)+(*sr:b){2}', 0, 29], ['eof', '', 29, 0]],
                'tree' => '852db7556151dc65',
                'error' => null,
            ],
        ],
    ],
    'body of a valid UTF-8 text in a pattern that is not UTF-8' => [
        'pattern' => "/\xFF(*pla:(*pla:\xC3\xA9[\xC3\xA9-\xC3\xAA]))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['literal', "\xFF", 0, 1], ['pcre_verb', "pla:(*pla:\xC3\xA9[\xC3\xA9-\xC3\xAA])", 1, 23], ['eof', '', 24, 0]],
                'tree' => '26f1fd614e05ce00',
                'error' => null,
            ],
        ],
    ],
    'body of bytes that are no UTF-8, two deep' => [
        'pattern' => "/(*pla:(*pla:\xE9[\xE9-\xEA]))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*pla:\xE9[\xE9-\xEA])", 0, 20], ['eof', '', 20, 0]],
                'tree' => 'cb15c8e5159f5a31',
                'error' => null,
            ],
        ],
    ],
    'multibyte class range two deep, under u' => [
        'pattern' => "/(*pla:(*pla:[\xC3\xA9-\xC3\xAA]))/u",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', "pla:(*pla:[\xC3\xA9-\xC3\xAA])", 0, 21], ['eof', '', 21, 0]],
                'tree' => '8aca66fcf5836acb',
                'error' => null,
            ],
        ],
    ],
    'a hundred bodies deep' => [
        'pattern' => '/(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:a))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => true,
                'tokens' => [['pcre_verb', 'pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:a)))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))', 0, 701], ['eof', '', 701, 0]],
                'tree' => 'bc952e75d5815eb1',
                'error' => null,
            ],
        ],
    ],
    'a hundred bodies deep around a class left open' => [
        'pattern' => '/(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:[a))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => 'regex.charclass.unclosed@702: Unclosed character class "]" at end of input.',
                'tree' => 'regex.charclass.unclosed@702: Unclosed character class "]" at end of input.',
                'error' => 'regex.charclass.unclosed@702: Unclosed character class "]" at end of input.',
            ],
        ],
    ],
    'three hundred bodies deep' => [
        'pattern' => '/(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:a))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))/',
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:(*pla:a)))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))', 0, 2101], ['eof', '', 2101, 0]],
                'tree' => '47795e75804a001a',
                'error' => 'regex.group.nested_too_deep@1506: Parentheses are nested too deeply: PCRE allows at most 250 levels.',
            ],
        ],
    ],
    'error after two bodies' => [
        'pattern' => '/(*pla:(*pla:a))b)/',
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*pla:a)', 0, 15], ['literal', 'b', 15, 1], ['group_close', ')', 16, 1], ['eof', '', 17, 0]],
                'tree' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
                'error' => 'regex.group.unmatched_close@16: Unmatched closing parenthesis at position 16.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', 'pla:(*pla:a)', 0, 15], ['literal', 'b', 15, 1], ['group_close', ')', 16, 1], ['eof', '', 17, 0]],
                'tree' => 'regex.group.unmatched_close@17: Unmatched closing parenthesis at position 17.',
                'error' => 'regex.group.unmatched_close@17: Unmatched closing parenthesis at position 17.',
            ],
        ],
    ],
    'error two deep after a body' => [
        'pattern' => "/(*pla:(*pla:a)(*pla:\\x{}))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*pla:a)(*pla:\\x{})", 0, 26], ['eof', '', 26, 0]],
                'tree' => "regex.escape.digits_missing@23: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => "regex.escape.digits_missing@23: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'error in the pattern before an error two deep' => [
        'pattern' => "/a{3,1}(*pla:(*pla:\\x{}))/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['literal', 'a', 0, 1], ['quantifier', '{3,1}', 1, 5], ['pcre_verb', "pla:(*pla:\\x{})", 6, 18], ['eof', '', 24, 0]],
                'tree' => "regex.escape.digits_missing@21: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => 'regex.quantifier.invalid_range@5: Invalid quantifier range "{3,1}": min > max.',
            ],
        ],
    ],
    'error two deep before an error in the pattern' => [
        'pattern' => "/(*pla:(*pla:\\x{}))a{3,1}/",
        'reads' => [
            [
                'releases' => ['10.44', '10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:(*pla:\\x{})", 0, 18], ['literal', 'a', 18, 1], ['quantifier', '{3,1}', 19, 5], ['eof', '', 24, 0]],
                'tree' => "regex.escape.digits_missing@15: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => "regex.escape.digits_missing@15: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
            ],
        ],
    ],
    'error in a body before an error in the body inside it' => [
        'pattern' => "/(*pla:[z-a](*pla:\\x{}))/",
        'reads' => [
            [
                'releases' => ['10.44'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:[z-a](*pla:\\x{})", 0, 23], ['eof', '', 23, 0]],
                'tree' => "regex.escape.digits_missing@20: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => 'regex.range.reversed@9: Invalid range "z-a": start character comes after end character.',
            ],
            [
                'releases' => ['10.49'],
                'compiles' => false,
                'tokens' => [['pcre_verb', "pla:[z-a](*pla:\\x{})", 0, 23], ['eof', '', 23, 0]],
                'tree' => "regex.escape.digits_missing@20: Invalid hex escape \"\\x{}\": at least one hexadecimal digit is required.",
                'error' => 'regex.range.reversed@10: Invalid range "z-a": start character comes after end character.',
            ],
        ],
    ],
];
