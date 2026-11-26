<?php

namespace PHPSTORM_META;

registerArgumentsSet(
    'redos_thresholds',
    null,
    \PHPRegex\Redos\RedosSeverity::Low,
    \PHPRegex\Redos\RedosSeverity::Medium,
    \PHPRegex\Redos\RedosSeverity::High,
    \PHPRegex\Redos\RedosSeverity::Critical
);
