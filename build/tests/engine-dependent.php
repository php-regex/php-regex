<?php

declare(strict_types=1);

/*
 * The patterns the twin reads otherwise on PHP 7.4 and 8.0 than the original
 * on PHP 8.1, for the one reason the twin cannot change: the library asks
 * the running engine what it knows of Unicode, and PHP 7.4 and 8.0 bundle
 * PCRE2 10.35 (Unicode 13), PHP 8.1 PCRE2 10.39 (Unicode 14). Each pattern
 * names a letter Unicode 14 added; a script is read from the names each
 * release knows, whatever the engine. Only the PHP 7.4 and 8.0
 * runs read this list, and each pattern must still differ there: one that
 * reads the same is removed from it.
 */

return [
    '/(?<a>x)\k<𖫀>/u' => 'U+16AC0, a Tangsa letter, a word character from Unicode 14',
    '/(?<n>a)\g{aࡰ}/u' => 'U+0870, an Arabic letter, a word character from Unicode 14',
    '/\k<aࡰ>/u' => 'U+0870, an Arabic letter, a word character from Unicode 14',
];
