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
];

foreach ($getyourbdExtensions as $extension) {
    $additionaldomainfields[$extension][] = [
        'Name' => 'GetYourBD NID',
        'Type' => 'text',
        'Size' => '30',
        'Required' => true,
        'Description' => '10, 13, or 17 digit National ID number.',
    ];

    $additionaldomainfields[$extension][] = [
        'Name' => 'GetYourBD Contact Number',
        'Type' => 'text',
        'Size' => '30',
        'Required' => true,
        'Description' => 'Bangladesh mobile number in +880XXXXXXXXXX format.',
    ];

    $additionaldomainfields[$extension][] = [
        'Name' => 'GetYourBD NID Document',
        'Type' => 'text',
        'Size' => '80',
        'Required' => true,
        'Description' => 'Server file path, relative document path, or HTTPS URL if enabled in registrar settings.',
    ];

    $additionaldomainfields[$extension][] = [
        'Name' => 'GetYourBD Registration Document',
        'Type' => 'text',
        'Size' => '80',
        'Required' => false,
        'Description' => 'Optional server file path or HTTPS URL for TLDs that require a supporting document.',
    ];
}
