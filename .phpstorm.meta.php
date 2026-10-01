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
    'php_version'
);

registerArgumentsSet(
    'optimize_option_keys',
    'digits',
    'word',
    'ranges',
    'autoPossessify',
    'allowAlternationFactorization',
    'minQuantifierCount'
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

registerArgumentsSet(
    'redos_thresholds',
    null,
    \PHPRegex\Redos\RedosSeverity::SAFE,
    \PHPRegex\Redos\RedosSeverity::LOW,
    \PHPRegex\Redos\RedosSeverity::MEDIUM,
    \PHPRegex\Redos\RedosSeverity::HIGH,
    \PHPRegex\Redos\RedosSeverity::CRITICAL,
    \PHPRegex\Redos\RedosSeverity::UNKNOWN
);

expectedArguments(
    \PHPRegex\Toolkit\Regex::create(),
    0,
    argumentsSet('regex_option_keys')
);

expectedArguments(
    \PHPRegex\Parser\ParserOptions::fromArray(),
    0,
    argumentsSet('regex_option_keys')
);

expectedArguments(
    \PHPRegex\Toolkit\Regex::optimize(),
    1,
    argumentsSet('optimize_option_keys')
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
    \PHPRegex\Toolkit\Regex::redos(),
    1,
    argumentsSet('redos_thresholds')
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

override(
    \PHPRegex\Toolkit\Regex::parse(1),
    map([
        true => \PHPRegex\Parser\TolerantParseResult::class,
        false => \PHPRegex\Parser\Node\RegexNode::class,
    ])
);
