<?php

declare(strict_types=1);

/**
 * Writes back as PHPDoc the native types the downgrade removes without a
 * tag: mixed, which PHP 7.4 cannot declare, leaves a parameter or a return
 * with no type at all. Each function and method of the twin is compared to
 * the original, read by reflection through this checkout's autoloader; a
 * parameter or a return the twin types neither natively nor in its PHPDoc
 * gets the original's type as a tag. A twin function the original does not
 * have stops the build.
 */
require __DIR__ . '/../vendor/autoload.php';

$root = $argv[1] ?? __DIR__ . '/php74/Parser';
$restored = 0;

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}

	$path = $file->getPathname();
	$source = (string) file_get_contents($path);
	$edits = [];

	foreach (functionsOf($source) as $function) {
		$original = originalOf($function, $path);
		if ($original === null) {
			continue;
		}

		$doc = $function['doc'];
		$tags = [];
		foreach ($original->getParameters() as $parameter) {
			$name = $parameter->getName();
			$twinTyped = $function['params'][$name] ?? null;
			if ($twinTyped === null) {
				fwrite(STDERR, sprintf("restore-docblock-types: %s of %s has no parameter \$%s\n", $function['name'], $path, $name));
				exit(1);
			}

			if (!$twinTyped && $parameter->hasType() && !preg_match('/@param\s+[^$]*\$' . preg_quote($name, '/') . '\b/', $doc)) {
				$tags[] = '@param ' . spell($parameter->getType()) . ' $' . $name;
			}
		}

		if (!$function['returnTyped'] && $original->hasReturnType() && !preg_match('/@return\b/', $doc)) {
			$tags[] = '@return ' . spell($original->getReturnType());
		}

		if ($tags !== []) {
			$edits[] = [$function, $tags];
			$restored += count($tags);
		}
	}

	// from the end of the file back, so the offsets read stay true
	usort($edits, static fn (array $a, array $b): int => $b[0]['start'] <=> $a[0]['start']);
	foreach ($edits as [$function, $tags]) {
		$source = withTags($source, $function, $tags);
	}

	if ($edits !== []) {
		file_put_contents($path, $source);
	}
}

echo "native types written back as PHPDoc: $restored\n";

/**
 * Each function of the file: its name, the class it belongs to, its PHPDoc,
 * which parameters carry a native type, whether the return does, and where
 * the declaration starts.
 *
 * @return list<array{name: string, class: ?string, doc: string, docStart: ?int, docEnd: ?int, start: int, indent: string, params: array<string, bool>, returnTyped: bool}>
 */
function functionsOf(string $source): array
{
	$tokens = token_get_all($source);
	$offsets = [];
	$offset = 0;
	foreach ($tokens as $i => $token) {
		$offsets[$i] = $offset;
		$offset += strlen(is_array($token) ? $token[1] : $token);
	}

	$namespace = '';
	$class = null;
	$classDepth = null;
	$depth = 0;
	$functions = [];
	$count = count($tokens);

	for ($i = 0; $i < $count; $i++) {
		$token = $tokens[$i];
		if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
			$depth++;
			continue;
		}

		if ($token === '}') {
			$depth--;
			if ($classDepth !== null && $depth === $classDepth) {
				$class = null;
				$classDepth = null;
			}

			continue;
		}

		if (!is_array($token)) {
			continue;
		}

		if ($token[0] === T_NAMESPACE) {
			$namespace = '';
			for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
				if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
					$namespace .= $tokens[$j][1];
				}
			}

			continue;
		}

		if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && $class === null) {
			$previous = previousSignificant($tokens, $i);
			if ($previous !== null && is_array($tokens[$previous]) && $tokens[$previous][0] === T_DOUBLE_COLON) {
				continue;
			}

			$name = nextSignificant($tokens, $i);
			if ($name !== null && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING) {
				$class = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$name][1];
				$classDepth = $depth;
			}

			continue;
		}

		if ($token[0] !== T_FUNCTION) {
			continue;
		}

		$nameIndex = nextSignificant($tokens, $i);
		if ($nameIndex === null || $tokens[$nameIndex] === '&') {
			$nameIndex = $nameIndex === null ? null : nextSignificant($tokens, $nameIndex);
		}

		// a closure has no name
		if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
			continue;
		}

		// the declaration starts at its first modifier
		$start = $i;
		for ($j = previousSignificant($tokens, $i); $j !== null && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true); $j = previousSignificant($tokens, $j)) {
			$start = $j;
		}

		$doc = '';
		$docStart = null;
		$docEnd = null;
		$before = previousSignificant($tokens, $start, false);
		if ($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_DOC_COMMENT) {
			$doc = $tokens[$before][1];
			$docStart = $offsets[$before];
			$docEnd = $docStart + strlen($doc);
		}

		$lineStart = strrpos(substr($source, 0, $offsets[$start]), "\n");
		$indent = substr($source, $lineStart === false ? 0 : $lineStart + 1, $offsets[$start] - ($lineStart === false ? 0 : $lineStart + 1));

		// the parameters: a variable is typed when a type token stands before
		// it in its own parameter
		$params = [];
		$open = nextSignificant($tokens, $nameIndex);
		$paren = 0;
		$typed = false;
		$expectingParam = true;
		for ($j = $open; $j < $count; $j++) {
			$t = $tokens[$j];
			if ($t === '(') {
				$paren++;
				continue;
			}

			if ($t === ')') {
				$paren--;
				if ($paren === 0) {
					break;
				}

				continue;
			}

			if ($paren !== 1) {
				continue;
			}

			if ($t === ',') {
				$typed = false;
				$expectingParam = true;
				continue;
			}

			if ($t === '=') {
				$expectingParam = false;
				continue;
			}

			if (!$expectingParam || !is_array($t)) {
				continue;
			}

			if ($t[0] === T_VARIABLE) {
				$params[substr($t[1], 1)] = $typed;
				$expectingParam = false;
			} elseif (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE], true)) {
				$typed = true;
			}
		}

		$returnTyped = false;
		$after = nextSignificant($tokens, $j);
		if ($after !== null && $tokens[$after] === ':') {
			$returnTyped = true;
		}

		$functions[] = [
			'name' => $tokens[$nameIndex][1],
			'class' => $class,
			'namespace' => $namespace,
			'doc' => $doc,
			'docStart' => $docStart,
			'docEnd' => $docEnd,
			'start' => $offsets[$start],
			'indent' => $indent,
			'params' => $params,
			'returnTyped' => $returnTyped,
		];
	}

	return $functions;
}

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
function previousSignificant(array $tokens, int $i, bool $skipDoc = true): ?int
{
	$skipped = $skipDoc ? [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT] : [T_WHITESPACE, T_COMMENT];
	for ($j = $i - 1; $j >= 0; $j--) {
		if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], $skipped, true)) {
			return $j;
		}
	}

	return null;
}

/**
 * The original function or method the twin's declaration stands for.
 *
 * @param array{name: string, class: ?string, namespace: string} $function
 */
function originalOf(array $function, string $path): ?ReflectionFunctionAbstract
{
	try {
		if ($function['class'] === null) {
			$name = ($function['namespace'] === '' ? '' : $function['namespace'] . '\\') . $function['name'];

			return function_exists($name) ? new ReflectionFunction($name) : null;
		}

		// an enum is written by emit-enum-factories.php, not by the downgrade
		if (enum_exists($function['class'])) {
			return null;
		}

		return new ReflectionMethod($function['class'], $function['name']);
	} catch (ReflectionException $exception) {
		fwrite(STDERR, sprintf("restore-docblock-types: %s::%s in %s has no original: %s\n", $function['class'] ?? '', $function['name'], $path, $exception->getMessage()));
		exit(1);
	}
}

/**
 * A native type as PHPDoc: class names fully qualified.
 */
function spell(ReflectionType $type): string
{
	if ($type instanceof ReflectionNamedType) {
		$name = $type->isBuiltin() ? $type->getName() : '\\' . ltrim($type->getName(), '\\');

		return $type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' . $name : $name;
	}

	$glue = $type instanceof ReflectionIntersectionType ? '&' : '|';
	$parts = [];
	foreach ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : [] as $part) {
		$parts[] = spell($part);
	}

	return implode($glue, $parts);
}

/**
 * The source with the tags added to the declaration's PHPDoc, or with a
 * PHPDoc holding them written above it.
 *
 * @param array{doc: string, docStart: ?int, docEnd: ?int, start: int, indent: string} $function
 * @param list<string>                                                                 $tags
 */
function withTags(string $source, array $function, array $tags): string
{
	$indent = $function['indent'];
	$lines = '';
	foreach ($tags as $tag) {
		$lines .= $indent . ' * ' . $tag . "\n";
	}

	if ($function['docStart'] === null) {
		return substr($source, 0, $function['start']) . "/**\n" . $lines . $indent . " */\n" . $indent . substr($source, $function['start']);
	}

	$doc = $function['doc'];
	$close = strrpos($doc, '*/');
	$body = rtrim(substr($doc, 0, $close));
	if (strpos($body, "\n") === false) {
		// a one-line PHPDoc opens onto its own lines
		$body = '/**' . "\n" . $indent . ' * ' . trim(substr($body, 3));
	}

	$doc = $body . "\n" . $indent . " *\n" . $lines . $indent . ' */';

	return substr($source, 0, $function['docStart']) . $doc . substr($source, $function['docEnd']);
}
