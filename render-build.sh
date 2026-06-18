#!/usr/bin/env bash
# Script de build pour Render.com

set -o errexit

echo "🚀 Installation des dépendances Composer..."
composer install --no-dev --optimize-autoloader

echo "🔑 Génération de la clé d'application..."
php artisan key:generate --force

echo "🗄️ Exécution des migrations..."
php artisan migrate --force

echo "🌱 Exécution des seeders (admin)..."
php artisan db:seed --class=AdminSeeder --force

echo "⚡ Optimisation de Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Build terminé avec succès!"
