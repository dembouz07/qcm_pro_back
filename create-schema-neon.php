<?php

/**
 * Créer le schéma directement sur Neon via PDO
 */

echo "=== Création du schéma sur Laravel Cloud/Neon ===\n\n";

$dsn = "pgsql:host=ep-mute-pond-a5f7tiwq.aws-us-east-2.pg.laravel.cloud;dbname=main;port=5432;sslmode=require;options='--client-encoding=UTF8 endpoint=ep-mute-pond-a5f7tiwq'";
$username = "laravel";
$password = "npg_Kuvz8bhB2JRC";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "✅ Connecté à la base de données\n\n";
    
    // Lire le schéma depuis database.sqlite local
    $localDb = new PDO('sqlite:database/database.sqlite');
    $localDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Obtenir toutes les tables
    $tables = $localDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Tables trouvées: " . implode(', ', $tables) . "\n\n";
    
    foreach ($tables as $table) {
        echo "→ Création de la table: $table\n";
        
        // Obtenir le schéma de la table
        $schema = $local_db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
        
        // Convertir SQLite vers PostgreSQL (simplifié)
        $pgSchema = str_replace('autoincrement', 'generated always as identity', strtolower($schema));
        $pgSchema = str_replace('integer', 'serial', $pgSchema);
        
        try {
            $pdo->exec($pgSchema);
            echo "  ✓ Créée\n";
        } catch (Exception $e) {
            echo "  ⚠ Erreur: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✅ Schéma créé!\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
