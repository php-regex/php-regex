<?php

namespace PHPSTORM_META;

registerArgumentsSet(
    'regex_option_keys',
    'max_pattern_length',
    'max_lookbehind_length',
    'cache',
    'redos_ignored_patterns',
    'runtime_pcre_validation',
    'max_recursion_depth',
    'php_version',
    'pcre_version'
);

registerArgumentsSet(
    'regex_flags',
    'i',
    'm',
    's',
    'x',
    'u',
    'A',
    'D',
    'S',
    'U',
    'X',
    'J',
    'r'
);

registerArgumentsSet(
    'regex_delimiters',
    '/',
    '#',
    '~',
    '%',
    '@',
    '!',
    '`'
);

expectedArguments(
    \PHPRegex\Parser\ParserOptions::fromArray(),
    0,
    argumentsSet('regex_option_keys')
);
