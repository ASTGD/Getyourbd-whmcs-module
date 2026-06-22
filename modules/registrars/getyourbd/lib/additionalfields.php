<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$getyourbdExtensions = [
    '.bd',
    '.com.bd',
    '.net.bd',
    '.org.bd',
    '.edu.bd',
    '.ac.bd',
    '.gov.bd',
    '.mil.bd',
    '.info.bd',
    '.বাংলা',
    '.co.bd',
    '.tv.bd',
    '.id.bd',
    '.sch.bd',
    '.ai.bd',
];

foreach ($getyourbdExtensions as $extension) {
    $additionaldomainfields[$extension][] = [
        'Name' => 'NID Full Name',
        'Type' => 'text',
        'Size' => '60',
        'Required' => true,
        'Description' => 'Enter the full name exactly as it appears on the NID.',
    ];

    $additionaldomainfields[$extension][] = [
        'Name' => 'NID',
        'Type' => 'text',
        'Size' => '30',
        'Required' => true,
        'Description' => '10, 13, or 17 digit National ID number.',
    ];

    $additionaldomainfields[$extension][] = [
        'Name' => 'Mobile Number',
        'Type' => 'text',
        'Size' => '30',
        'Required' => true,
        'Description' => 'Bangladesh mobile number in +880XXXXXXXXXX format.',
    ];

    $additionaldomainfields[$extension][] = [
        'Name' => 'NID Document',
        'Type' => 'text',
        'Size' => '80',
        'Required' => true,
        'Description' => 'Upload a JPG, PNG, or PDF copy of the NID.',
    ];

    $additionaldomainfields[$extension][] = [
        'Name' => 'Registration Document',
        'Type' => 'text',
        'Size' => '80',
        'Required' => false,
        'Description' => 'Upload an optional supporting JPG, PNG, or PDF document.',
    ];
}
