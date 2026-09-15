<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Workspace Manifest File Path
    |--------------------------------------------------------------------------
    |
    | The default absolute or relative path to the workspace.json manifest file.
    |
    */
    'path' => env('WORKSPACE_MANIFEST_PATH', base_path('workspace.json')),
];
