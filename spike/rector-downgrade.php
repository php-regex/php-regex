<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeSetList;

return RectorConfig::configure()
	->withPaths([__DIR__ . '/out/Parser'])
	->withSets([
		DowngradeSetList::PHP_81,
		DowngradeSetList::PHP_80,
		DowngradeSetList::PHP_74,
	])
;
