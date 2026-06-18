<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;

class NeonConnector extends PostgresConnector
{
    /**
     * Create a DSN string from a configuration.
     *
     * @param  array  $config
     * @return string
     */
    protected function getDsn(array $config)
    {
        // Get the base DSN from parent
        $dsn = parent::getDsn($config);
        
        // Add Neon-specific endpoint option (working format)
        if (isset($config['endpoint'])) {
            $dsn .= ";options='--client-encoding=UTF8 endpoint={$config['endpoint']}'";
        }
        
        return $dsn;
    }
}
