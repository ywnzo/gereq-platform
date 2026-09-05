<?php

declare(strict_types=1);

return [
    // self_repair = built-in typo/diacritics search (no external service)
    // Future: meilisearch | elasticsearch
    'driver' => env_value('SEARCH_DRIVER', 'self_repair'),
    'typo_max_variants' => (int) env_value('SEARCH_TYPO_MAX_VARIANTS', 14),
    'min_token_length' => (int) env_value('SEARCH_MIN_TOKEN_LENGTH', 3),
];
