<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use GetYourBd\ApiClient;
use GetYourBd\DocumentResolver;
use GetYourBd\FieldExtractor;
use GetYourBd\Installer;
use GetYourBd\OrderRepository;

function getyourbd_MetaData()
{
    return [
        'DisplayName' => 'GetYourBD Partner API',
        'APIVersion' => '1.1',
    ];
}

function getyourbd_getConfigArray()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'GetYourBD Partner API',
        ],
        'PartnerUserId' => [
            'FriendlyName' => 'Partner User ID',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'HTTP Basic Auth username from GetYourBD.',
        ],
        'PartnerPassword' => [
            'FriendlyName' => 'Partner Password',
            'Type' => 'password',
            'Size' => '40',
            'Description' => 'HTTP Basic Auth password from GetYourBD.',
        ],
        'ApiBaseUrl' => [
            'FriendlyName' => 'API Base URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://getyour.com.bd',
            'Description' => 'Defaults to the production endpoint documented by GetYourBD.',
        ],
        'ApiTimeout' => [
            'FriendlyName' => 'API Timeout',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '30',
            'Description' => 'Timeout in seconds for partner API calls.',
        ],
        'DefaultNameserver1' => [
            'FriendlyName' => 'Default Nameserver 1',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Used only if WHMCS does not pass nameservers for the order.',
        ],
        'DefaultNameserver2' => [
            'FriendlyName' => 'Default Nameserver 2',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Used only if WHMCS does not pass nameservers for the order.',
        ],
        'DefaultNameserver3' => [
            'FriendlyName' => 'Default Nameserver 3',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional third nameserver; the GetYourBD API accepts up to three.',
        ],
        'DocumentBasePath' => [
            'FriendlyName' => 'Document Base Path',
            'Type' => 'text',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Optional. Relative document references must resolve inside this path.',
        ],
        'AllowRemoteDocumentUrls' => [
            'FriendlyName' => 'Allow Remote Document URLs',
            'Type' => 'yesno',
            'Description' => 'Permit HTTPS public URLs in the NID and registration document fields.',
        ],
        'DocumentFetchTimeout' => [
            'FriendlyName' => 'Document Fetch Timeout',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '20',
            'Description' => 'Timeout in seconds for remote document downloads.',
        ],
        'DebugMode' => [
            'FriendlyName' => 'Debug Module Log',
            'Type' => 'yesno',
            'Description' => 'Include non-secret request metadata in WHMCS Module Log.',
        ],
    ];
}

function getyourbd_RegisterDomain($params)
{
    $nidDocument = null;
    $registrationDocument = null;

    try {
        Installer::ensureOrderTable();

        $payload = FieldExtractor::buildPayload($params);
        $resolver = new DocumentResolver(
            defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 3),
            (string) ($params['DocumentBasePath'] ?? ''),
            ((string) ($params['AllowRemoteDocumentUrls'] ?? '')) === 'on',
            (int) ($params['DocumentFetchTimeout'] ?? 20)
        );

        $nidDocument = $resolver->resolveRequired(
            $payload['nidDocumentReference'],
            FieldExtractor::FIELD_NID_DOCUMENT
        );
        $registrationDocument = $resolver->resolveOptional(
            $payload['registrationDocumentReference'],
            FieldExtractor::FIELD_REGISTRATION_DOCUMENT
        );

        $client = new ApiClient(
            (string) ($params['ApiBaseUrl'] ?? 'https://getyour.com.bd'),
            (string) ($params['PartnerUserId'] ?? ''),
            (string) ($params['PartnerPassword'] ?? ''),
            (int) ($params['ApiTimeout'] ?? 30),
            ((string) ($params['DebugMode'] ?? '')) === 'on'
        );

        $response = $client->createDomainOrder($payload, $nidDocument, $registrationDocument);
        OrderRepository::recordSuccess($params, $payload, $response);

        return [
            'success' => true,
        ];
    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    } finally {
        DocumentResolver::cleanup($nidDocument);
        DocumentResolver::cleanup($registrationDocument);
    }
}
