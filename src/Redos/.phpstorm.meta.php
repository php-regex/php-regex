<?php

namespace PHPSTORM_META;

registerArgumentsSet(
    'redos_thresholds',
    \PHPRegex\Redos\RedosSeverity::Low,
    \PHPRegex\Redos\RedosSeverity::Medium,
    \PHPRegex\Redos\RedosSeverity::High,
    \PHPRegex\Redos\RedosSeverity::Critical
);

expectedArguments(
    \PHPRegex\Redos\RedosOptions::__construct(),
    1,
    argumentsSet('redos_thresholds')
);
