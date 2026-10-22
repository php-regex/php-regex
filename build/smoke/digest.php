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
    static $enumTwins = [
        'PHPRegex\\Parser\\ErrorCode', 'PHPRegex\\Parser\\TraversalAction',
        'PHPRegex\\Parser\\BsrConvention', 'PHPRegex\\Parser\\NewlineConvention',
        'PHPRegex\\Parser\\PcreFeature', 'PHPRegex\\Parser\\Token\\TokenType',
        'PHPRegex\\Parser\\Analysis\\LiteralConfidence', 'PHPRegex\\Parser\\Analysis\\Participation',
        'PHPRegex\\Parser\\Hir\\AssertionKind', 'PHPRegex\\Parser\\Hir\\Greed',
        'PHPRegex\\Parser\\Hir\\LookKind', 'PHPRegex\\Parser\\Printer\\FormatRefusal',
        'PHPRegex\\Parser\\Node\\QuantifierType', 'PHPRegex\\Parser\\Node\\ClassSetOperator',
        'PHPRegex\\Parser\\Node\\GroupType', 'PHPRegex\\Parser\\Node\\CharLiteralType',
        'PHPRegex\\Parser\\Validation\\ValidationErrorCategory',
    ];

	$result = ['c' => (new ReflectionClass($node))->getShortName()];

	$props = [];
	foreach ((new ReflectionObject($node))->getProperties() as $property) {
		if (!$property->isPublic() || in_array($property->getName(), ['startPosition', 'endPosition'], true)) {
			continue;
		}

		$value = $property->getValue($node);
		if ($value instanceof UnitEnum || (is_object($value) && in_array(get_class($value), $enumTwins, true))) {
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

// the reference runs the ORIGINAL parser but judges for the same target the
// twin serves: how each PHP reads the escapes its engine refuses (a \x{...}
// without /u becomes the letter x then text on 7.4) is target behaviour,
// not a divergence
$parser = RegexParser::create(['php_version' => '7.4']);

foreach (corpus() as $body) {
	$pattern = '/' . $body . '/';

	try {
		$ast = $parser->parse($pattern);
		echo json_encode(['p' => $body, 'ok' => true, 'd' => digest($ast->pattern)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
	} catch (Throwable $e) {
		echo json_encode(['p' => $body, 'ok' => false, 'e' => get_class($e)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
	}
}
