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
    'description'      => 'Reusable scanner API and frontend component for barcode and QR code scanning',
    'state'            => 'alpha',
    'title'            => 'PSBits | Scanner',
    'version'          => '0.2.0',
];
