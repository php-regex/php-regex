<?php

declare(strict_types=1);

use PHPRegex\Build\Rector\DowngradeLeftoversRector;
use PHPRegex\Build\Rector\PropertyOrderRector;
use PHPRegex\Build\Rector\SingleArraySetRector;
use PHPRegex\Build\Rector\StrictSwitchRector;
use Rector\Config\RectorConfig;

// The second pass over the downgraded tree: what the downgrade sets write
// that does not mean what the source means, or that the twin does not need.
require_once __DIR__ . '/rector/StrictSwitchRector.php';
require_once __DIR__ . '/rector/DowngradeLeftoversRector.php';
require_once __DIR__ . '/rector/PropertyOrderRector.php';
require_once __DIR__ . '/rector/SingleArraySetRector.php';

return RectorConfig::configure()
	->withPaths([__DIR__ . '/php74/Parser'])
	->withRules([
		StrictSwitchRector::class,
		DowngradeLeftoversRector::class,
		PropertyOrderRector::class,
		SingleArraySetRector::class,
	])
;
