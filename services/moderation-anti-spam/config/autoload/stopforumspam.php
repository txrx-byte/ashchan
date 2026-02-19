<?php

declare(strict_types=1);

return [
    'api_key' => env('STOPFORUMSPAM_API_KEY', ''),
    'threshold' => (int) env('STOPFORUMSPAM_THRESHOLD', 80),
];
