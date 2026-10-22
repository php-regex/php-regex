<?php

declare(strict_types=1);

/**
 * Functional digest of the parser over the shared corpus: one JSON line per
 * pattern body, AST structure reduced to class names, scalar properties and
 * child trees. Enum properties normalize to their backing value so the 8.x
 * original and the downgraded twin produce comparable lines.
 *
 * Usage: php digest.php <autoload-path>
 */

require $argv[1];

use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\RegexParser;

require __DIR__ . '/../corpus.php';

/**
 * @return array<string, mixed>
 */
function digest(NodeInterface $node): array
{
	$result = ['c' => (new ReflectionClass($node))->getShortName()];

	$props = [];
	foreach ((new ReflectionObject($node))->getProperties() as $property) {
		if (!$property->isPublic() || in_array($property->getName(), ['startPosition', 'endPosition'], true)) {
			continue;
		}

		$value = $property->getValue($node);
		if ($value instanceof UnitEnum) {
			$value = $value->value;
		}

		if (is_object($value)) {
			$value = 'obj:' . get_class($value);
		} elseif (is_array($value)) {
			$value = 'arr:' . count($value);
		}

		$props[$property->getName()] = $value;
	}

	$result['p'] = $props;

	$children = [];
	foreach ($node->getChildren() as $child) {
		$children[] = digest($child);
	}

	if ($children !== []) {
		$result['ch'] = $children;
	}

	return $result;
}

$parser = RegexParser::create();

foreach (corpus() as $body) {
	$pattern = '/' . $body . '/';

	try {
		$ast = $parser->parse($pattern);
		echo json_encode(['p' => $body, 'ok' => true, 'd' => digest($ast->pattern)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
	} catch (Throwable $e) {
		echo json_encode(['p' => $body, 'ok' => false, 'e' => get_class($e)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
	}
}
