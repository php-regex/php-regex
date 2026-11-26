<?php

namespace PHPSTORM_META;

registerArgumentsSet(
    'regex_create_option_keys',
    'max_pattern_length',
    'max_lookbehind_length',
    'cache',
    'runtime_pcre_validation',
    'max_recursion_depth',
    'php_version',
    'pcre_version',
    'locale',
    'redos_ignored_patterns'
);

registerArgumentsSet(
    'explanation_formats',
    'text',
    'html'
);

registerArgumentsSet(
    'highlight_formats',
    'console',
    'html'
);

expectedArguments(
    \PHPRegex\Toolkit\Regex::create(),
    0,
    argumentsSet('regex_create_option_keys')
);

expectedArguments(
    \PHPRegex\Toolkit\Regex::explain(),
    1,
    argumentsSet('explanation_formats')
);

expectedArguments(
    \PHPRegex\Toolkit\Regex::highlight(),
    1,
    argumentsSet('highlight_formats')
);

expectedArguments(
    \PHPRegex\Toolkit\Regex::parsePattern(),
    1,
    argumentsSet('regex_flags')
);

expectedArguments(
    \PHPRegex\Toolkit\Regex::parsePattern(),
    2,
    argumentsSet('regex_delimiters')
);

