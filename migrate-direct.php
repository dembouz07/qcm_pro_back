<?php

/**
 * Migration directe via PDO - Solution de contournement pour Neon
 */

echo "=== Migration directe vers Laravel Cloud/Neon ===\n\n";

// Configuration de connexion
$dsn = "pgsql:host=ep-mute-pond-a5f7tiwq.aws-us-east-2.pg.laravel.cloud;dbname=main;port=5432;sslmode=require;options='--client-encoding=UTF8 endpoint=ep-mute-pond-a5f7tiwq'";
$username = "laravel";
$password = "npg_Kuvz8bhB2JRC";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "✅ Connecté à la base de données\n\n";
    
    // Liste des fichiers de migration
    $migrationFiles = glob(__DIR__ . '/database/migrations/*.php');
    sort($migrationFiles);
    
    foreach ($migrationFiles as $file) {
        $migrationName = basename($file, '.php');
        echo "→ Exécution: $migrationName\n";
        
        require_once $file;
        
        // Extraire le nom de la classe depuis le nom de fichier
        $className = implode('', array_map('ucfirst', explode('_', substr($migrationName, 18))));
        
        if (class_exists($className)) {
            $migration = new $className;
            
            // Utiliser PDO directement dans la migration
            $migration->up();
            
            echo "  ✓ Terminé\n";
        }
    }
    
    echo "\n✅ Toutes les migrations ont été exécutées avec succès!\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
