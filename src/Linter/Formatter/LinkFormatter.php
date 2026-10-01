<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Linter\Formatter;

final readonly class LinkFormatter
{
    private const IDE_LINK_FORMATS = [
        'textmate' => 'txmt://open?url=file://%f&line=%l',
        'macvim' => 'mvim://open?url=file://%f&line=%l',
        'emacs' => 'emacs://open?url=file://%f&line=%l',
        'sublime' => 'subl://open?url=file://%f&line=%l',
        'phpstorm' => 'phpstorm://open?file=%f&line=%l',
        'atom' => 'atom://core/open/file?filename=%f&line=%l',
        'vscode' => 'vscode://file/%f:%l',
    ];

    private bool $supportsHyperlinks;

    public function __construct(private ?string $editorUrlTemplate, private RelativePathHelper $relativePathHelper)
    {
        $this->supportsHyperlinks = $this->detectHyperlinkSupport();
    }

    public function format(string $file, ?int $line, string $label, int $column = 1, ?string $fallbackLabel = null): string
    {
        if (null === $line) {
            return $label;
        }

        $url = $this->buildEditorUrl($file, $line, $column);
        if (null === $url) {
            return $label;
        }

        if (!$this->supportsHyperlinks) {
            if (null !== $fallbackLabel) {
                return $fallbackLabel;
            }

            return $this->relativePathHelper->getRelativePath($file).':'.$line;
        }

        return \sprintf('<href=%s>%s</>', $this->escape($url), $label);
    }

    public function getRelativePath(string $file): string
    {
        return $this->relativePathHelper->getRelativePath($file);
    }

    private function buildEditorUrl(string $file, int $line, int $column): ?string
    {
        if (null === $this->editorUrlTemplate || '' === $this->editorUrlTemplate) {
            return null;
        }

        $resolvedFile = $this->resolveFilePath($file);
        if (null === $resolvedFile) {
            return null;
        }

        $template = self::IDE_LINK_FORMATS[$this->editorUrlTemplate] ?? $this->editorUrlTemplate;
        $relativeFile = $this->relativePathHelper->getRelativePath($resolvedFile);
        $encodedFile = $this->encodePath($resolvedFile);
        $encodedRelativeFile = $this->encodePath($relativeFile);

        return strtr($template, [
            '%f' => $encodedFile,
            '%file%' => $encodedFile,
            '%l' => (string) $line,
            '%line%' => (string) $line,
            '%c' => (string) $column,
            '%column%' => (string) $column,
            '%relFile%' => $encodedRelativeFile,
        ]);
    }

    private function resolveFilePath(string $file): ?string
    {
        $normalized = $this->normalizePath($file);

        if (!$this->looksLikePath($normalized)) {
            return null;
        }

        if ($this->isAbsolutePath($normalized)) {
            return $normalized;
        }

        $basePath = $this->relativePathHelper->getBasePath();
        if (null === $basePath || '' === $basePath) {
            return $normalized;
        }

        $basePath = rtrim($this->normalizePath($basePath), '/');

        return $basePath.'/'.ltrim($normalized, '/');
    }

    private function looksLikePath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $path)) {
            return true;
        }

        if (str_contains($path, '/')) {
            return true;
        }

        return (bool) preg_match('/\.[A-Za-z0-9]+$/', $path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        return str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || (bool) preg_match('/^[A-Za-z]:\//', $path)
            || (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $path);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function encodePath(string $path): string
    {
        $encoded = rawurlencode($path);

        return str_replace(['%2F', '%3A'], ['/', ':'], $encoded);
    }

    private function detectHyperlinkSupport(): bool
    {
        if ('JetBrains-JediTerm' === getenv('TERMINAL_EMULATOR')) {
            return false;
        }

        $konsoleVersion = getenv('KONSOLE_VERSION');
        if ($konsoleVersion && (int) $konsoleVersion <= 201100) {
            return false;
        }

        if (isset($_SERVER['IDEA_INITIAL_DIRECTORY'])) {
            return false;
        }

        return true;
    }

    /**
     * Escapes "<" and ">" special chars in given text.
     */
    private function escape(string $text): string
    {
        $result = preg_replace('/([^\\\\]|^)([<>])/', '$1\\\\$2', $text);
        if (null === $result) {
            return $text; // fallback to original text if regex fails
        }
        $text = $result;

        if (str_ends_with($text, '\\')) {
            $len = \strlen($text);
            $text = rtrim($text, '\\');
            $text = str_replace("\0", '', $text);
            $text .= str_repeat("\0", $len - \strlen($text));
        }

        return $text;
    }
}
