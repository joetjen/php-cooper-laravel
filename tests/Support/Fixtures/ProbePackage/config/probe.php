<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Probe Name
    |--------------------------------------------------------------------------
    |
    | The name the probe reports itself by.
    |
    */

    'name' => 'probe',

    // Whether the probe runs at all.
    'enabled' => env('PROBE_ENABLED', true),
    'retries' => env('PROBE_RETRIES', 3),
    'ratio' => env('PROBE_RATIO', 0.5),
    'region' => env('PROBE_REGION', null),
    'driver' => env('PROBE_DRIVER', 'file'),
    'url' => 'https://' . env('PROBE_HOST', 'localhost') . ':8080/probe',
    'hosts' => explode(',', env('PROBE_HOSTS', 'a.example,b.example')),
    'model' => Illuminate\Foundation\Auth\User::class,
    'storage' => storage_path('probe'),
    'database' => env('PROBE_DATABASE', database_path('probe.sqlite')),
    'error_mode' => PDO::ERRMODE_EXCEPTION,
    'prefix' => Str::slug(env('APP_NAME', 'laravel'), '_') . '_probe_',
    'tags' => ['a', 'b'],
    'connections' => [
        'main' => ['host' => '127.0.0.1', 'port' => 5432],
    ],

];
