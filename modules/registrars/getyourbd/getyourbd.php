<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use GetYourBd\ApiClient;
use GetYourBd\DocumentResolver;
use GetYourBd\DomainDataManager;
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
        'DefaultNameserver1' => [
            'FriendlyName' => 'Default Nameserver 1',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'ns1.btcl.com.bd',
            'Description' => 'Used only if WHMCS does not pass nameservers for the order.',
        ],
        'DefaultNameserver2' => [
            'FriendlyName' => 'Default Nameserver 2',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'ns2.btcl.com.bd',
            'Description' => 'Used only if WHMCS does not pass nameservers for the order.',
        ],
        'DefaultNameserver3' => [
            'FriendlyName' => 'Default Nameserver 3',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'ns3.btcl.com.bd',
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
        $params = DomainDataManager::enrichRegistrarParams($params);

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
            'https://getyour.com.bd',
            (string) ($params['PartnerUserId'] ?? ''),
            (string) ($params['PartnerPassword'] ?? ''),
            30,
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

function getyourbd_GetNameservers($params)
{
    try {
        FieldExtractor::assertSupportedDomain(FieldExtractor::buildDomainName($params));
        $current = OrderRepository::currentNameservers($params);

        return [
            'ns1' => (string) ($current['ns1'] ?? ''),
            'ns2' => (string) ($current['ns2'] ?? ''),
            'ns3' => (string) ($current['ns3'] ?? ''),
            'ns4' => '',
            'ns5' => '',
        ];
    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}

function getyourbd_GetDomainInformation($params)
{
    try {
        $domain = FieldExtractor::buildDomainName($params);
        FieldExtractor::assertSupportedDomain($domain);

        $class = '\\WHMCS\\Domain\\Registrar\\Domain';
        if (!class_exists($class)) {
            return getyourbd_GetNameservers($params);
        }

        $current = OrderRepository::currentNameservers($params);
        $nameservers = [];
        for ($index = 1; $index <= 5; $index++) {
            $value = trim((string) ($current['ns' . $index] ?? ''));
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        $domainInformation = new $class();
        if (method_exists($domainInformation, 'setDomain')) {
            $domainInformation->setDomain($domain);
        }
        if (method_exists($domainInformation, 'setNameservers')) {
            $domainInformation->setNameservers($nameservers);
        }
        if (method_exists($domainInformation, 'setRegistrationStatus')) {
            $domainInformation->setRegistrationStatus((string) ($params['status'] ?? ''));
        }

        return $domainInformation;
    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}

function getyourbd_SaveNameservers($params)
{
    try {
        $payload = FieldExtractor::buildNameserverUpdatePayload($params);

        if (OrderRepository::isPendingDomain($params)) {
            OrderRepository::saveNameserversLocally($params, $payload['nameServers']);

            return [
                'success' => true,
            ];
        }

        $client = new ApiClient(
            'https://getyour.com.bd',
            (string) ($params['PartnerUserId'] ?? ''),
            (string) ($params['PartnerPassword'] ?? ''),
            30,
            ((string) ($params['DebugMode'] ?? '')) === 'on'
        );

        $response = $client->updateNameservers($payload['domain'], $payload['nameServers']);
        OrderRepository::recordNameserverUpdate($params, $payload, $response);

        return [
            'success' => true,
        ];
    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}
