# Magento 2 Read/Write Database Split Module

A Magento 2 module that implements automatic read/write database splitting. Read queries are distributed across multiple read replicas using round-robin selection, while write operations always go to the master database. CLI operations (indexing, cron, console commands) always use the master to avoid temporary table conflicts.
We ensure a 'writer first' approach and include a writer fallback for defensive posturing.
It is built to support multiple readers, but 1 will work just as well. 

## Configuration

Add reader connection configurations to `app/etc/env.php`:

```php
'db' => [
    'connection' => [
        'default' => [
            'host' => 'master-db-host',
            'dbname' => 'magento',
            'username' => 'dbuser',
            'password' => 'dbpass',
            'model' => 'mysql4',
            'engine' => 'innodb',
            'initStatements' => 'SET NAMES utf8;',
            'active' => '1',
            'driver_options' => [
                1014 => false
            ]
        ],
        'indexer' => [
            'host' => 'master-db-host',
            'dbname' => 'magento',
            'username' => 'dbuser',
            'password' => 'dbpass',
            'model' => 'mysql4',
            'engine' => 'innodb',
            'initStatements' => 'SET NAMES utf8;',
            'active' => '1'
        ]
    ],
    'reader_connections' => [
        'default' => [
            // Note this is an array, even if only using 1 you must keep this structure
            [
                'host' => 'reader-1-host',
                'dbname' => 'magento',
                'username' => 'dbuser',
                'password' => 'dbpass',
                'model' => 'mysql4',
                'engine' => 'innodb',
                'initStatements' => 'SET NAMES utf8;',
                'active' => '1',
                'driver_options' => [
                    1014 => false
                ]
            ],
            [
                'host' => 'reader-2-host',
                'dbname' => 'magento',
                'username' => 'dbuser',
                'password' => 'dbpass',
                'model' => 'mysql4',
                'engine' => 'innodb',
                'initStatements' => 'SET NAMES utf8;',
                'active' => '1',
                'driver_options' => [
                    1014 => false
                ]
            ]
        ]
    ]
],
```

## How It Works

- SELECT, SHOW, DESCRIBE, EXPLAIN queries are routed to reader connections
- INSERT, UPDATE, DELETE, DDL, and transaction queries go to master
- Queries within transactions always use master for consistency
- CLI operations (bin/magento commands) always use master
- Failed reader connections automatically fall back to master
- Round-robin load balancing across active readers

## Sticky Writer (intentional)

Once any write occurs in a request, all subsequent reads in that same request are pinned to the master. This is deliberate, not a bug. Magento commonly writes an entity and immediately reads it back in the same request (e.g. saving a product then loading it to build the redirect URL in admin). A read replica that has not yet caught up via replication would return stale data or no row at all, causing redirects to 404s, missing form data, or broken admin flows. Pinning to master after the first write trades a small amount of read offload for correctness.

## Disabling Readers

Set `'active' => '0'` for any reader connection to temporarily disable it without removing the configuration.

## Requirements

- PHP 8.0 or higher
- Magento 2.4+
- MySQL master-slave replication configured
- Read replicas must be in sync with master

## Notes

The indexer connection is never split and always uses the master database to avoid issues with temporary tables during reindexing operations.
