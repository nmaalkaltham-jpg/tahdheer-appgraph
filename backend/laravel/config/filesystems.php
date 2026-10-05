<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * أصول مدفوعة — قرص محلّي خاص (المطوّر الافتراضي).
         * Paid originals on a private local disk (the DEV default).
         * 'serve' => false is a security requirement, not a preference: it keeps
         * Laravel from registering a /storage/{path} route for this disk, so a
         * paid object key is not addressable over HTTP at all. Delivery happens
         * ONLY through the grant-bound redeem endpoint.
         */
        'assets' => [
            'driver' => 'local',
            'root' => storage_path('app/private/assets'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        /*
         * Garage 2.x (S3-compatible, MIP §4.3). Path-style endpoints are
         * mandatory — Garage does not serve virtual-host bucket addressing.
         * Requires league/flysystem-aws-s3-v3; while that package is absent the
         * provider stays configured-but-inert and the resolver falls back to
         * the local disk (logged once, never silently).
         *
         * env: GARAGE_ACCESS_KEY_ID, GARAGE_SECRET_ACCESS_KEY, GARAGE_BUCKET,
         *      GARAGE_ENDPOINT, GARAGE_REGION (AWS_* accepted as a fallback).
         */
        'garage' => [
            'driver' => 's3',
            'key' => env('GARAGE_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('GARAGE_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('GARAGE_REGION', env('AWS_DEFAULT_REGION', 'garage')),
            'bucket' => env('GARAGE_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('GARAGE_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Assets Domain (App\Domain\Assets)
    |--------------------------------------------------------------------------
    |
    | provider: garage|local — the resolver falls back to local when Garage is
    | unavailable. url_ttl: the ceiling for a delivery URL in seconds; the real
    | TTL is the SHORTER of this value and the remaining download-grant window.
    |
    */

    'assets' => [
        'provider' => env('ASSETS_STORAGE_PROVIDER', 'local'),
        'local_disk' => env('ASSETS_LOCAL_DISK', 'assets'),
        'garage_disk' => 'garage',
        'url_ttl' => (int) env('ASSETS_URL_TTL_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
