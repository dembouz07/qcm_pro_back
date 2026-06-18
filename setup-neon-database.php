<?php

/**
 * Créer les tables et l'admin directement sur Laravel Cloud/Neon
 */

echo "=== Configuration de la base de données Laravel Cloud ===\n\n";

$dsn = "pgsql:host=ep-mute-pond-a5f7tiwq.aws-us-east-2.pg.laravel.cloud;dbname=main;port=5432;sslmode=require;options='--client-encoding=UTF8 endpoint=ep-mute-pond-a5f7tiwq'";
$username = "laravel";
$password = "npg_Kuvz8bhB2JRC";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "✅ Connecté à la base de données\n\n";
    
    // 1. Table users
    echo "→ Création de la table 'users'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            email_verified_at TIMESTAMP NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'student',
            school_class_id INTEGER NULL,
            remember_token VARCHAR(100) NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )
    ");
    echo "  ✓ users créée\n";
    
    // 2. Table school_classes
    echo "→ Création de la table 'school_classes'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS school_classes (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            description TEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )
    ");
    echo "  ✓ school_classes créée\n";
    
    // 3. Table quizzes
    echo "→ Création de la table 'quizzes'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quizzes (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            school_class_id INTEGER NOT NULL,
            duration_minutes INTEGER NOT NULL,
            opens_at TIMESTAMP NOT NULL,
            closes_at TIMESTAMP NOT NULL,
            is_published BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (school_class_id) REFERENCES school_classes(id) ON DELETE CASCADE
        )
    ");
    echo "  ✓ quizzes créée\n";
    
    // 4. Table questions
    echo "→ Création de la table 'questions'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS questions (
            id SERIAL PRIMARY KEY,
            quiz_id INTEGER NOT NULL,
            question_text TEXT NOT NULL,
            points INTEGER DEFAULT 1,
            order_index INTEGER DEFAULT 0,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        )
    ");
    echo "  ✓ questions créée\n";
    
    // 5. Table choices
    echo "→ Création de la table 'choices'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS choices (
            id SERIAL PRIMARY KEY,
            question_id INTEGER NOT NULL,
            choice_text TEXT NOT NULL,
            is_correct BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
        )
    ");
    echo "  ✓ choices créée\n";
    
    // 6. Table submissions
    echo "→ Création de la table 'submissions'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS submissions (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            quiz_id INTEGER NOT NULL,
            submitted_at TIMESTAMP NULL,
            score NUMERIC(5,2) NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        )
    ");
    echo "  ✓ submissions créée\n";
    
    // 7. Table submission_answers
    echo "→ Création de la table 'submission_answers'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS submission_answers (
            id SERIAL PRIMARY KEY,
            submission_id INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            choice_id INTEGER NULL,
            is_correct BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            FOREIGN KEY (choice_id) REFERENCES choices(id) ON DELETE SET NULL
        )
    ");
    echo "  ✓ submission_answers créée\n";
    
    // 8. Table cache
    echo "→ Création de la table 'cache'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cache (
            key VARCHAR(255) PRIMARY KEY,
            value TEXT NOT NULL,
            expiration INTEGER NOT NULL
        )
    ");
    echo "  ✓ cache créée\n";
    
    // 9. Table cache_locks
    echo "→ Création de la table 'cache_locks'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cache_locks (
            key VARCHAR(255) PRIMARY KEY,
            owner VARCHAR(255) NOT NULL,
            expiration INTEGER NOT NULL
        )
    ");
    echo "  ✓ cache_locks créée\n";
    
    // 10. Table sessions
    echo "→ Création de la table 'sessions'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(255) PRIMARY KEY,
            user_id INTEGER NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            payload TEXT NOT NULL,
            last_activity INTEGER NOT NULL
        )
    ");
    echo "  ✓ sessions créée\n";
    
    // 11. Table jobs
    echo "→ Création de la table 'jobs'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jobs (
            id SERIAL PRIMARY KEY,
            queue VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            attempts SMALLINT NOT NULL,
            reserved_at INTEGER NULL,
            available_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )
    ");
    echo "  ✓ jobs créée\n";
    
    // 12. Table personal_access_tokens
    echo "→ Création de la table 'personal_access_tokens'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS personal_access_tokens (
            id SERIAL PRIMARY KEY,
            tokenable_type VARCHAR(255) NOT NULL,
            tokenable_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            abilities TEXT NULL,
            last_used_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )
    ");
    echo "  ✓ personal_access_tokens créée\n";
    
    echo "\n=== Création de l'utilisateur admin ===\n";
    
    // Vérifier si l'admin existe déjà
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'admin@example.com'");
    $adminExists = $stmt->fetchColumn() > 0;
    
    if (!$adminExists) {
        // Hash du mot de passe 'password' avec bcrypt
        $passwordHash = password_hash('password', PASSWORD_BCRYPT);
        
        $pdo->exec("
            INSERT INTO users (name, email, password, role, created_at, updated_at)
            VALUES ('Admin', 'admin@example.com', '$passwordHash', 'admin', NOW(), NOW())
        ");
        echo "✅ Admin créé : admin@example.com / password\n";
    } else {
        echo "⚠ Admin existe déjà\n";
    }
    
    echo "\n✅ BASE DE DONNÉES CONFIGURÉE AVEC SUCCÈS!\n\n";
    echo "Identifiants admin:\n";
    echo "  Email: admin@example.com\n";
    echo "  Mot de passe: password\n\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
