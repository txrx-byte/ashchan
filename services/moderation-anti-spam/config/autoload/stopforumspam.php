<?php

declare(strict_types=1);

return [
    'api_key' => getenv('STOPFORUMSPAM_API_KEY') ?: '',
    'threshold' => (int) (getenv('STOPFORUMSPAM_THRESHOLD') ?: 80),
];
