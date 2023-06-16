<?php
use Illuminate\Database\Capsule\Manager;

$capsule = new Manager();
$capsule->addConnection(config('database.connections.mysql'));
return [
    'enable' => true,
    'paths' => [
        "seeds"      => "database/seeders"
    ],
    'migration_table' => 'migrations',
    'db' => $capsule->getDatabaseManager()
];