<?php

declare(strict_types=1);

return [
    'frontend' => [
        'psbits/scanner/api' => [
            'target' => \PSBits\Scanner\Middleware\ScannerApiMiddleware::class,
        ],
    ],
];
