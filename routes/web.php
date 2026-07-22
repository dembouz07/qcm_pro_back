<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Route nommée "login" : l'app est une API pure (pas de page de connexion serveur).
// Elle évite l'erreur "Route [login] not defined" quand une requête non authentifiée
// (token expiré/absent, sans en-tête Accept: application/json) atteint une route protégée.
Route::get('/login', function () {
    return response()->json(['message' => 'Non authentifié. Veuillez vous reconnecter.'], 401);
})->name('login');

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
