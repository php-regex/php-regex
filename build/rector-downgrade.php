<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DowngradePhp80\Rector\Enum_\DowngradeEnumToConstantListClassRector;
use Rector\Set\ValueObject\DowngradeSetList;

return RectorConfig::configure()
	->withPaths([__DIR__ . '/php74/Parser'])
	// PHP_81 brings 8.1 code to 8.0 and PHP_80 brings it to 7.4, the twin's
	// floor; PHP_74 would go on to 7.3 and drop the typed properties
	->withSets([
		DowngradeSetList::PHP_81,
		DowngradeSetList::PHP_80,
	])
	// the enums are written as classes by emit-enum-factories.php: the rule
	// would also strip every parameter, return and property typed with one
	->withSkip([
		DowngradeEnumToConstantListClassRector::class,
	])
;
