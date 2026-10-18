<?php

namespace PHPSTORM_META;

registerArgumentsSet(
    'redos_thresholds',
    null,
    \PHPRegex\Redos\RedosSeverity::Safe,
    \PHPRegex\Redos\RedosSeverity::Low,
    \PHPRegex\Redos\RedosSeverity::Medium,
    \PHPRegex\Redos\RedosSeverity::Unknown,
    \PHPRegex\Redos\RedosSeverity::High,
    \PHPRegex\Redos\RedosSeverity::Critical
);
