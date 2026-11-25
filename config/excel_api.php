<?php

return [
    // Sent by clients in the X-API-Key header.
    'api_key' => env('API_KEY'),

    // Safety limits for one upload.
    'max_rows' => (int) env('EXCEL_API_MAX_ROWS', 50000),
    'max_columns' => (int) env('EXCEL_API_MAX_COLUMNS', 100),
];
