<?php

namespace App\Providers;

use App\Database\NeonConnector;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Connectors\ConnectionFactory;

class NeonDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Replace ConnectionFactory BEFORE it's instantiated
        $this->app->singleton(ConnectionFactory::class, function ($app) {
            return new class($app) extends ConnectionFactory {
                protected function createConnector(array $config)
                {
                    // Use NeonConnector for PostgreSQL connections
                    if (($config['driver'] ?? null) === 'pgsql') {
                        return new NeonConnector();
                    }
                    
                    return parent::createConnector($config);
                }
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
