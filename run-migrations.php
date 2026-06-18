<?php

// Exécuter les migrations via Tinker (qui fonctionne!)
echo shell_exec('php artisan tinker --execute="Artisan::call(\'migrate\', [\'--force\' => true]); echo Artisan::output();"');
