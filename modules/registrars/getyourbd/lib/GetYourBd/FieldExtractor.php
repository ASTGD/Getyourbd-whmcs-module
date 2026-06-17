<?php

namespace GetYourBd;

use InvalidArgumentException;

class FieldExtractor
{
    public const FIELD_NID = 'GetYourBD NID';
    public const FIELD_CONTACT_NUMBER = 'GetYourBD Contact Number';
    public const FIELD_NID_DOCUMENT = 'GetYourBD NID Document';
    public const FIELD_REGISTRATION_DOCUMENT = 'GetYourBD Registration Document';

    private const SUPPORTED_TLDS = [
        'bd',
        'com.bd',
        'net.bd',
        'org.bd',
        'edu.bd',
        'ac.bd',
        'gov.bd',
        'mil.bd',
        'info.bd',
        'বাংলা',
    ];

    public static function buildDomainName(array $params): string
    {
        if (!empty($params['domain'])) {
            return self::lower(trim((string) $params['domain']));
        }

        $sld = trim((string) ($params['sld'] ?? ''));
        $tld = ltrim(trim((string) ($params['tld'] ?? '')), '.');

        return self::lower($sld . '.' . $tld);
    }

    public static function assertSupportedDomain(string $domain): void
    {
        foreach (self::SUPPORTED_TLDS as $tld) {
            if (self::endsWith($domain, '.' . $tld) || $domain === $tld) {
                return;
            }
        }

        throw new InvalidArgumentException('GetYourBD only supports .bd family and .বাংলা domain registrations.');
    }

    public static function buildPayload(array $params): array
    {
        $additionalFields = is_array($params['additionalfields'] ?? null)
            ? $params['additionalfields']
            : [];

        $domain = self::buildDomainName($params);
        self::assertSupportedDomain($domain);

        $nid = self::fieldValue($additionalFields, [
            self::FIELD_NID,
            'NID',
            'National ID',
            'National ID Number',
        ]);
        if (!preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nid)) {
            throw new InvalidArgumentException('GetYourBD NID must be 10, 13, or 17 digits.');
        }

        $contactNumber = self::fieldValue($additionalFields, [self::FIELD_CONTACT_NUMBER]);
        if ($contactNumber === '') {
            $contactNumber = (string) ($params['fullphonenumber'] ?? $params['phonenumber'] ?? '');
        }
        $contactNumber = self::normaliseBangladeshPhone($contactNumber);
        if (!preg_match('/^\+880\d{10}$/', $contactNumber)) {
            throw new InvalidArgumentException('GetYourBD contact number must be in +880XXXXXXXXXX format.');
        }

        $nameservers = self::nameservers($params);
        if (count($nameservers) < 2) {
            throw new InvalidArgumentException('At least two nameservers are required for GetYourBD domain orders.');
        }

        $fullName = trim((string) ($params['fullname'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string) ($params['firstname'] ?? '') . ' ' . (string) ($params['lastname'] ?? ''));
        }
        if ($fullName === '') {
            throw new InvalidArgumentException('Registrant full name is required for GetYourBD domain orders.');
        }

        $email = trim((string) ($params['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid registrant email address is required for GetYourBD domain orders.');
        }

        $contactAddress = self::contactAddress($params);
        if ($contactAddress === '') {
            throw new InvalidArgumentException('Registrant contact address is required for GetYourBD domain orders.');
        }

        $years = (int) ($params['regperiod'] ?? 1);
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException('GetYourBD registration period must be between 1 and 10 years.');
        }

        return [
            'domain' => $domain,
            'nameServers' => $nameservers,
            'fullName' => $fullName,
            'nid' => $nid,
            'email' => $email,
            'contactAddress' => $contactAddress,
            'contactNumber' => $contactNumber,
            'years' => $years,
            'nidDocumentReference' => self::fieldValue($additionalFields, [
                self::FIELD_NID_DOCUMENT,
                'NID Document',
                'NID Document Path',
                'NID Document URL',
            ]),
            'registrationDocumentReference' => self::fieldValue($additionalFields, [
                self::FIELD_REGISTRATION_DOCUMENT,
                'Registration Document',
                'Registration Document Path',
                'Registration Document URL',
            ]),
        ];
    }

    private static function nameservers(array $params): array
    {
        $nameservers = [];
        for ($index = 1; $index <= 5; $index++) {
            $value = trim((string) ($params['ns' . $index] ?? ''));
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        if (count($nameservers) < 2) {
            foreach (['DefaultNameserver1', 'DefaultNameserver2', 'DefaultNameserver3'] as $key) {
                $value = trim((string) ($params[$key] ?? ''));
                if ($value !== '' && !in_array($value, $nameservers, true)) {
                    $nameservers[] = $value;
                }
            }
        }

        return array_slice($nameservers, 0, 3);
    }

    private static function contactAddress(array $params): string
    {
        $parts = [
            $params['address1'] ?? '',
            $params['address2'] ?? '',
            $params['city'] ?? '',
            $params['state'] ?? '',
            $params['postcode'] ?? '',
            $params['countryname'] ?? $params['country'] ?? '',
        ];

        $parts = array_filter(array_map(static function ($value) {
            return trim((string) $value);
        }, $parts));

        return implode(', ', $parts);
    }

    private static function fieldValue(array $fields, array $names): string
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $fields)) {
                return trim((string) $fields[$name]);
            }
        }

        $normalised = [];
        foreach ($fields as $key => $value) {
            $normalised[self::normaliseFieldName((string) $key)] = $value;
        }

        foreach ($names as $name) {
            $key = self::normaliseFieldName($name);
            if (array_key_exists($key, $normalised)) {
                return trim((string) $normalised[$key]);
            }
        }

        return '';
    }

    private static function normaliseFieldName(string $name): string
    {
        $name = self::lower($name);
        $name = preg_replace('/[^a-z0-9\p{L}]+/u', '', $name);

        return (string) $name;
    }

    private static function normaliseBangladeshPhone(string $phone): string
    {
        $phone = trim($phone);
        $phone = str_replace([' ', '-', '(', ')', '.'], '', $phone);

        if (strpos($phone, '00880') === 0) {
            $phone = '+' . substr($phone, 2);
        } elseif (strpos($phone, '880') === 0) {
            $phone = '+' . $phone;
        } elseif (strpos($phone, '0') === 0 && strlen($phone) === 11) {
            $phone = '+88' . $phone;
        }

        return $phone;
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        if ($length === 0) {
            return true;
        }

        return substr($haystack, -$length) === $needle;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
