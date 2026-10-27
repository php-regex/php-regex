<?php

declare(strict_types=1);

/**
 * Rewrites, in the code of the downgraded tree, `Enum::Case` into
 * `Enum::Case()`, the one change the classes written in place of the enums
 * need, and `\UnitEnum` / `\BackedEnum` into the twin's interfaces. The
 * rewrite reads tokens, never text: a string or a comment that spells a
 * case keeps its spelling. A name is resolved against the file's namespace
 * and imports, and rewritten only when it names an enum of src/Parser and
 * one of its cases: `Enum::class`, `Enum::from()` and `Enum::cases()` stay.
 */
require __DIR__ . '/../vendor/autoload.php';

$root = __DIR__ . '/php74/Parser';

$rewrites = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}

	$path = $file->getPathname();
	$tokens = token_get_all((string) file_get_contents($path));
	$namespace = '';
	$imports = [];
	$out = '';
	$count = count($tokens);
	$changed = 0;
	$depth = 0;

	for ($i = 0; $i < $count; $i++) {
		$token = $tokens[$i];
		$text = is_array($token) ? $token[1] : $token;

		if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
			$depth++;
		} elseif ($token === '}') {
			$depth--;
		}

		if (is_array($token) && $token[0] === T_NAMESPACE) {
			$namespace = nameAfter($tokens, $i);
		} elseif (is_array($token) && $token[0] === T_USE && $depth === 0) {
			// an import: the use of a closure or of a trait stands in braces
			foreach (importsAfter($tokens, $i) as $alias => $fqcn) {
				$imports[strtolower($alias)] = $fqcn;
			}
		}

		if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED && in_array($token[1], ['\\UnitEnum', '\\BackedEnum'], true)) {
			$out .= '\\PHPRegex\\Parser' . $token[1];
			$changed++;
			continue;
		}

		if (is_array($token) && $token[0] === T_DOC_COMMENT) {
			$text = (string) preg_replace('/(?<![\w\\\\])\\\\(UnitEnum|BackedEnum)\b/', '\\\\PHPRegex\\\\Parser\\\\$1', $text, -1, $docRewrites);
			$changed += $docRewrites;
		}

		if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
			$colon = $i + 1;
			$case = $i + 2;
			$after = nextSignificant($tokens, $case);
			if ($colon < $count && is_array($tokens[$colon]) && $tokens[$colon][0] === T_DOUBLE_COLON
				&& $case < $count && is_array($tokens[$case]) && $tokens[$case][0] === T_STRING
				&& ($after === null || $tokens[$after] !== '(')) {
				$enum = resolve($token[1], $namespace, $imports);
				if (enum_exists($enum) && (new ReflectionEnum($enum))->hasCase($tokens[$case][1])) {
					$out .= $text . '::' . $tokens[$case][1] . '()';
					$i = $case;
					$changed++;
					continue;
				}
			}
		}

		$out .= $text;
	}

	if ($changed > 0) {
		file_put_contents($path, $out);
		$rewrites += $changed;
	}
}

echo "enum cases and interfaces rewritten: $rewrites\n";

/**
 * @param array<int, mixed> $tokens
 */
function nextSignificant(array $tokens, int $i): ?int
{
	for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
		if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
			return $j;
		}
	}

	return null;
}

/**
 * @param array<int, mixed> $tokens
 */
function nameAfter(array $tokens, int $i): string
{
	$name = '';
	for ($j = $i + 1, $count = count($tokens); $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
		if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
			$name .= $tokens[$j][1];
		}
	}

	return ltrim($name, '\\');
}

/**
 * The names a top-level `use` imports, by the alias each is read under. A
 * grouped, function or const import stops the build: the source writes
 * none.
 *
 * @param array<int, mixed> $tokens
 *
 * @return array<string, string>
 */
function importsAfter(array $tokens, int $i): array
{
	$imports = [];
	$name = '';
	$alias = null;
	$expectAlias = false;
	for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
		$token = $tokens[$j];
		if ($token === ';' || $token === ',') {
			if ($name !== '') {
				$parts = explode('\\', ltrim($name, '\\'));
				$imports[$alias ?? end($parts)] = ltrim($name, '\\');
			}

			if ($token === ';') {
				break;
			}

			$name = '';
			$alias = null;
			$expectAlias = false;
			continue;
		}

		if ($token === '{' || (is_array($token) && in_array($token[0], [T_FUNCTION, T_CONST], true))) {
			fwrite(STDERR, "rewrite-enum-calls: a grouped, function or const import, which the source does not write\n");
			exit(1);
		}

		if (is_array($token) && $token[0] === T_AS) {
			$expectAlias = true;
		} elseif (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
			if ($expectAlias) {
				$alias = $token[1];
			} else {
				$name .= $token[1];
			}
		}
	}

	return $imports;
}

/**
 * @param array<string, string> $imports
 */
function resolve(string $name, string $namespace, array $imports): string
{
	if ($name[0] === '\\') {
		return substr($name, 1);
	}

	$first = explode('\\', $name)[0];
	if (isset($imports[strtolower($first)])) {
		return $imports[strtolower($first)] . substr($name, strlen($first));
	}

	return ($namespace === '' ? '' : $namespace . '\\') . $name;
}
