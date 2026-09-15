<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Workspace Manifest File Path
    |--------------------------------------------------------------------------
    |
    | The path to the workspace manifest JSON file. Leave null to use the
    | package's internal default filename (workspace.json).
    |
    */
    'path' => env('WORKSPACE_MANIFEST_PATH', null),
];
