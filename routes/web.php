<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Route de diagnostic pour vérifier les extensions PHP
Route::get('/phpinfo', function () {
    if (app()->environment('production')) {
        return response()->json([
            'php_version' => PHP_VERSION,
            'extensions' => get_loaded_extensions(),
            'zip_enabled' => extension_loaded('zip'),
            'xml_enabled' => extension_loaded('xml'),
            'gd_enabled' => extension_loaded('gd'),
        ]);
    }
    
    phpinfo();
})->name('phpinfo');
