<?php

require_once dirname(__DIR__, 3) . '/init.php';
require_once ROOTDIR . '/modules/registrars/getyourbd/lib/bootstrap.php';

use GetYourBd\UploadManager;

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Only POST uploads are accepted.');
    }

    if (function_exists('check_token')) {
        check_token('WHMCS.default');
    }

    if (empty($_FILES['document']) || !is_array($_FILES['document'])) {
        throw new RuntimeException('No document was uploaded.');
    }

    $result = UploadManager::store($_FILES['document'], (string) ($_POST['field_type'] ?? ''));
    echo json_encode([
        'success' => true,
        'reference' => $result['reference'],
        'filename' => $result['filename'],
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
