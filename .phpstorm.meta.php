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
    \PhpRegex\Redos\RedosSeverity::SAFE,
    \PhpRegex\Redos\RedosSeverity::LOW,
    \PhpRegex\Redos\RedosSeverity::MEDIUM,
    \PhpRegex\Redos\RedosSeverity::HIGH,
    \PhpRegex\Redos\RedosSeverity::CRITICAL,
    \PhpRegex\Redos\RedosSeverity::UNKNOWN
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::create(),
    0,
    argumentsSet('regex_option_keys')
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::new(),
    0,
    argumentsSet('regex_option_keys')
);

expectedArguments(
    \PhpRegex\Parser\ParserOptions::fromArray(),
    0,
    argumentsSet('regex_option_keys')
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::optimize(),
    1,
    argumentsSet('optimize_option_keys')
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::explain(),
    1,
    argumentsSet('explanation_formats')
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::highlight(),
    1,
    argumentsSet('highlight_formats')
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::redos(),
    1,
    argumentsSet('redos_thresholds')
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::parsePattern(),
    1,
    argumentsSet('regex_flags')
);

expectedArguments(
    \PhpRegex\Toolkit\Regex::parsePattern(),
    2,
    argumentsSet('regex_delimiters')
);

override(
    \PhpRegex\Toolkit\Regex::parse(1),
    map([
        true => \PhpRegex\Parser\TolerantParseResult::class,
        false => \PhpRegex\Parser\Node\RegexNode::class,
    ])
);
