<?php

$EM_CONF[$_EXTKEY] = [
    'author'           => 'Daniel Ablass',
    'author_email'     => 'dn@phantasie-schmiede.de',
    'category'         => 'misc',
    'clearCacheOnLoad' => false,
    'constraints'      => [
        'conflicts' => [],
        'depends'   => [
            'php'   => '8.3.0-8.5.99',
            'typo3' => '13.4.0-14.99.99',
        ],
        'suggests'  => [],
    ],
    'description'      => 'Reusable scanner API and frontend component for barcode scanning',
    'state'            => 'alpha',
    'title'            => 'PSBits | Scanner',
    'version'          => '0.1.0',
];
