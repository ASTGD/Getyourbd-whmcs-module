<?php

require_once dirname(__DIR__, 3) . '/init.php';
require_once ROOTDIR . '/modules/registrars/getyourbd/lib/bootstrap.php';

use GetYourBd\DomainDataManager;
use GetYourBd\FieldExtractor;
use GetYourBd\UploadManager;
use WHMCS\Database\Capsule;

header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($_SESSION['adminid'])) {
        throw new RuntimeException('An authenticated WHMCS administrator is required.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Only POST uploads are accepted.');
    }
    if (function_exists('check_token')) {
        check_token('WHMCS.admin.default');
    }

    $domainId = (int) ($_POST['domain_id'] ?? 0);
    $domain = Capsule::table('tbldomains')->where('id', $domainId)->first(['domain', 'registrar']);
    if (!$domain) {
        throw new RuntimeException('The selected WHMCS domain does not exist.');
    }
    if (strcasecmp((string) $domain->registrar, 'getyourbd') !== 0) {
        throw new RuntimeException('The selected domain is not assigned to the GetYourBD registrar.');
    }
    FieldExtractor::assertSupportedDomain((string) $domain->domain);

    if (empty($_FILES['document']) || !is_array($_FILES['document'])) {
        throw new RuntimeException('No document was uploaded.');
    }

    $fieldType = (string) ($_POST['field_type'] ?? '');
    $result = UploadManager::store($_FILES['document'], $fieldType);
    DomainDataManager::setDocumentReference($domainId, $fieldType, $result['reference']);

    echo json_encode([
        'success' => true,
        'filename' => $result['filename'],
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
