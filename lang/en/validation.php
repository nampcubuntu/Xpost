<?php

declare(strict_types=1);

return [
    'uploaded' => 'The file could not be uploaded. This usually means it exceeded PHP\'s own upload_max_filesize / post_max_size, not the app\'s limit.',

    'custom' => [
        'post' => [
            'empty' => 'A post needs either text or at least one media attachment.',
            'needs_time' => 'Pick a time to publish, or switch to immediate mode.',
            'needs_window' => 'Drip and jitter modes need an end time, a jitter window, or a per-minute cap.',
            'needs_variants' => 'This content strategy requires at least one variant.',
            'too_long' => 'The text weighs :weight characters by X\'s counting rules, over the :max limit.',
            'too_long_with_url' => 'Once the destination link is appended, the text goes over 280 characters.',
            'spintax_unbalanced' => 'The spintax braces { } are unbalanced.',
            'spintax_single_option' => 'A spintax group needs at least two options separated by |.',
        ],
    ],
];
