<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repository Mandal is released from. New servers download the
    | setup script and the CLI from its latest release, and a server that
    | hosts the panel deploys the panel from it.
    |
    */

    'repository' => env('MANDAL_REPOSITORY', 'ReyArlena/mandal'),

    /*
    |--------------------------------------------------------------------------
    | Backup imports
    |--------------------------------------------------------------------------
    |
    | A Docker app can start from a .zip, .tar.gz or .tar.zst of its project
    | folder. The upload limit is the panel's own; PHP's upload_max_filesize and
    | post_max_size, and nginx's client_max_body_size, must allow it too.
    | Archives that unpack to more than max_unpacked are refused.
    |
    */

    'imports' => [
        'max_upload' => env('MANDAL_IMPORT_MAX_UPLOAD', '1G'),
        'max_unpacked' => env('MANDAL_IMPORT_MAX_UNPACKED', '20G'),
    ],

];
