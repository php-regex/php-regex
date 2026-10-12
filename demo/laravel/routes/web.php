<?php

use Illuminate\Support\Facades\Route;
use PHPRegex\Laravel\Facades\Regex;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/regex-demo', function () {
    $patterns = [
        'date' => '/\d{4}-\d{2}-\d{2}/',
        'broken' => '/^(unclosed$/',
    ];

    $report = [];
    foreach ($patterns as $name => $pattern) {
        $result = Regex::validate($pattern);
        $report[$name] = [
            'pattern' => $pattern,
            'valid' => $result->isValid,
            'error' => $result->error,
        ];
    }

    return response()->json($report);
})->name('regex.demo');

// A route whose constraint is itself a regex: exactly the material the
// regex:routes and regex:lint commands read through the bridge.
Route::get('/regex-demo/slug/{slug}', function (string $slug) {
    return response()->json(['slug' => $slug, 'requirement' => '[a-z0-9-]+']);
})->where('slug', '[a-z0-9-]+')->name('regex.slug');

