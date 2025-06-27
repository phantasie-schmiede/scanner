<?php

$EM_CONF[$_EXTKEY] = [
    'author'           => 'Daniel Ablass',
    'author_email'     => 'dn@phantasie-schmiede.de',
    'category'         => 'module',
    'clearCacheOnLoad' => false,
    'constraints'      => [
        'conflicts' => [],
        'depends'   => [
            'typo3' => '11.5.0-13.4.99',
        ],
        'suggests'  => [],
    ],
    'description'      => 'Frontend partial for scanning barcodes and QR codes',
    'state'            => 'alpha',
    'title'            => 'PSBits | Scanner',
    'version'          => '0.1.0',
];
