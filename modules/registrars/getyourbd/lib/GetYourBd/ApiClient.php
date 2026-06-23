<?php

namespace GetYourBd;

use RuntimeException;

class ApiClient
{
    private string $baseUrl;
    private string $userId;
    private string $password;
    private int $timeout;
    private bool $debug;

    public function __construct(string $baseUrl, string $userId, string $password, int $timeout = 30, bool $debug = false)
    {
        $this->baseUrl = rtrim($baseUrl ?: 'https://getyour.com.bd', '/');
        $this->userId = $userId;
        $this->password = $password;
        $this->timeout = max(10, $timeout);
        $this->debug = $debug;
    }

    public function createDomainOrder(array $payload, array $nidDocument, ?array $registrationDocument = null): array
    {
        $this->assertConfigured();

        $fields = [
            'domain' => $payload['domain'],
            'nameServers[0]' => $payload['nameServers'][0],
            'nameServers[1]' => $payload['nameServers'][1],
            'fullName' => $payload['fullName'],
            'nidFullName' => $payload['nidFullName'],
            'nid' => $payload['nid'],
            'nid_document' => $nidDocument['curlFile'],
            'email' => $payload['email'],
            'contactAddress' => $payload['contactAddress'],
            'contactNumber' => $payload['contactNumber'],
            'years' => (string) $payload['years'],
        ];

        if (!empty($payload['nameServers'][2])) {
            $fields['nameServers[2]'] = $payload['nameServers'][2];
        }

        if ($registrationDocument) {
            $fields['registration_document'] = $registrationDocument['curlFile'];
        }

        $url = $this->baseUrl . '/api/v1/domain/orders';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->userId . ':' . $this->password,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->logCall('CreateDomainOrder', $url, $payload, $status, $decoded, $response, $error);

        if ($response === false) {
            throw new RuntimeException('GetYourBD API connection failed: ' . $error);
        }

        if ($status !== 201) {
            throw new RuntimeException($this->errorMessage($status, $decoded, $response));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('GetYourBD API returned an invalid JSON response.');
        }

        return $decoded;
    }

    public function updateNameservers(string $domain, array $nameservers): array
    {
        $this->assertConfigured();

        $payload = [
            'domain' => $domain,
            'nameServers' => array_values($nameservers),
        ];
        $body = json_encode($payload);
        if ($body === false) {
            throw new RuntimeException('Unable to encode GetYourBD nameserver payload.');
        }

        $url = $this->baseUrl . '/api/v1/domain/update-ns';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->userId . ':' . $this->password,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->logCall('UpdateNameservers', $url, $payload, $status, $decoded, $response, $error);

        if ($response === false) {
            throw new RuntimeException('GetYourBD API connection failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->errorMessage($status, $decoded, $response));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('GetYourBD API returned an invalid JSON response.');
        }

        return $decoded;
    }

    private function assertConfigured(): void
    {
        if (trim($this->userId) === '' || trim($this->password) === '') {
            throw new RuntimeException('GetYourBD Partner User ID and Password are required.');
        }

        if (!preg_match('/^https:\/\/[^\/]+/i', $this->baseUrl)) {
            throw new RuntimeException('GetYourBD API Base URL must be an HTTPS URL.');
        }
    }

    private function errorMessage(int $status, $decoded, $response): string
    {
        if (is_array($decoded)) {
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                $messages = [];
                foreach ($decoded['errors'] as $field => $errors) {
                    foreach ((array) $errors as $message) {
                        $messages[] = $field . ': ' . $message;
                    }
                }

                if ($messages) {
                    return 'GetYourBD API validation failed: ' . implode(' ', $messages);
                }
            }

            if (!empty($decoded['message'])) {
                return 'GetYourBD API error HTTP ' . $status . ': ' . $decoded['message'];
            }
        }

        return 'GetYourBD API error HTTP ' . $status . ': ' . substr((string) $response, 0, 500);
    }

    private function logCall(string $action, string $url, array $payload, int $status, $decoded, $response, string $error): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $request = [
            'url' => $url,
            'domain' => $payload['domain'] ?? '',
            'nameServers' => $payload['nameServers'] ?? [],
        ];
        if (isset($payload['years'])) {
            $request['years'] = $payload['years'];
        }

        if ($this->debug) {
            $request['email'] = $payload['email'] ?? '';
            $request['contactNumber'] = $payload['contactNumber'] ?? '';
            if (isset($payload['nid'])) {
                $request['nid'] = '[redacted]';
                $request['documents'] = '[redacted]';
            }
        }

        logModuleCall(
            'getyourbd',
            $action,
            $request,
            $decoded ?: $response ?: $error,
            ['status' => $status],
            ['password', 'nid', 'nid_document', 'registration_document']
        );
    }
}
