<?php

$EM_CONF[$_EXTKEY] = [
    'author'           => 'Daniel Ablass',
    'author_email'     => 'dn@phantasie-schmiede.de',
    'category'         => 'module',
    'clearCacheOnLoad' => false,
    'constraints'      => [
        'conflicts' => [],
        'depends'   => [
            'php'   => '8.3.0-8.5.99',
            'typo3' => '12.4.0-14.99.99',
        ],
        'suggests'  => [],
    ],
    'description'      => 'Frontend partial for scanning barcodes and QR codes',
    'state'            => 'alpha',
    'title'            => 'PSBits | Scanner',
    'version'          => '0.1.0',
];
