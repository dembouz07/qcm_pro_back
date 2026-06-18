<?php

// Test direct de connexion à Neon/Laravel Cloud sans Laravel

// Essai 1 : Utiliser le project endpoint dans le host
$dsn1 = "pgsql:host=ep-mute-pond-a5f7tiwq-pooler.aws-us-east-2.pg.laravel.cloud;dbname=main;port=5432;sslmode=require";

// Essai 2 : Ajouter endpoint via options avec format correct
$dsn2 = "pgsql:host=ep-mute-pond-a5f7tiwq.aws-us-east-2.pg.laravel.cloud;dbname=main;port=5432;sslmode=require;options='--client-encoding=UTF8 endpoint=ep-mute-pond-a5f7tiwq'";

// Essai 3 : Format Neon recommandé
$dsn3 = "pgsql:host=ep-mute-pond-a5f7tiwq.aws-us-east-2.pg.laravel.cloud;port=5432;dbname=main;sslmode=require";

$username = "laravel";
$password = "npg_Kuvz8bhB2JRC";

$tests = [
    'DSN 1 (pooler)' => $dsn1,
    'DSN 2 (options)' => $dsn2,
    'DSN 3 (simple)' => $dsn3
];

foreach ($tests as $label => $dsn) {
    echo "\n=== Testing $label ===\n";
    echo "$dsn\n\n";
    
    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        echo "✅ Connection successful!\n";
        echo "Database: " . $pdo->query('SELECT current_database()')->fetchColumn() . "\n";
        echo "User: " . $pdo->query('SELECT current_user')->fetchColumn() . "\n";
        break; // Stop on first success
        
    } catch (PDOException $e) {
        echo "❌ Connection failed:\n";
        echo $e->getMessage() . "\n";
    }
}

