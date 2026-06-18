<?php

$dsn = "pgsql:host=ep-mute-pond-a5f7tiwq.aws-us-east-2.pg.laravel.cloud;dbname=main;port=5432;sslmode=require;options='--client-encoding=UTF8 endpoint=ep-mute-pond-a5f7tiwq'";
$username = "laravel";
$password = "npg_Kuvz8bhB2JRC";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "=== État de la base de données ===\n\n";
    
    // Lister les tables
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Tables créées (" . count($tables) . ") :\n";
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "  ✓ $table ($count lignes)\n";
    }
    
    echo "\n=== Utilisateurs ===\n";
    $users = $pdo->query("SELECT id, name, email, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        echo "  • {$user['name']} ({$user['email']}) - Role: {$user['role']}\n";
    }
    
    echo "\n✅ Base de données opérationnelle!\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
}
