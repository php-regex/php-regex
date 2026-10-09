<?php

// A fixed sample of the repository corpus, linted by the benchmarks.

function lint_fixture_1(string $subject): void
{
    $matches = [];
    preg_match('/(\\r|\\n)+(\\s)/', $subject);
    preg_replace('/[^0-9]/isU', '', $subject);
    preg_split('/<link ([^>]*)[\\/]?>/isU', $subject);
    preg_match_all('/plugins\\/([a-zA-z]+)\\/\\n/', $subject, $matches);
    preg_match('/\\[(?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*\\]\\(( *(?:\\([^\\x17\\s()]*\\)|[^\\x17\\s)])*(?=[ )]) *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))? *)\\)/', $subject);
    preg_replace('/\\.([0-9])+\\-/', '', $subject);
    preg_split('/(?:\\\\?[A-Za-z_][\\w\\d_]*\\\\)+[A-Za-z_][\\w\\d_]*/', $subject);
    preg_match_all('~(?:INSERT|REPLACE)\\s+(?:IGNORE)?\\s*INTO `(.*)` \\((.*)\\) VALUES (\\(.*\\))+~', $subject, $matches);
    preg_match('/^<!---?[^>-](?:-?[^-])*-->/s', $subject);
    preg_replace('#((?<!/)/[^/]+)*/wp-content/plugins/wordpress/plugins/wpcomsh/([^/]+)/#', '', $subject);
    preg_split('/^`{32} example\\n((?s).*?)\\n\\.\\n(?:|((?s).*?)\\n)`{32}$|^#{1,6} *(.*?)$/m', $subject);
    preg_match_all('#([\\s>])([.0-9a-z_+-]+)@(([0-9a-z-]+\\.)+[0-9a-z]{2,})#i', $subject, $matches);
    preg_match('/\\,/i', $subject);
}
