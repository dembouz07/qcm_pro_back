<?php

/**
 * Script de migration direct pour Neon/Laravel Cloud
 * Contourne les problèmes de service provider
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force NeonConnector pour toutes les connexions pgsql
\Illuminate\Support\Facades\DB::connection()->setReconnector(function ($connection) {
    $connector = new \App\Database\NeonConnector();
    return $connector->connect($connection->getConfig());
});

// Exécuter les migrations
$exitCode = \Illuminate\Support\Facades\Artisan::call('migrate', [
    '--force' => true
]);

echo \Illuminate\Support\Facades\Artisan::output();

exit($exitCode);
