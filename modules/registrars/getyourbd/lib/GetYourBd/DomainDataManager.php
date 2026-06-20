<?php

namespace GetYourBd;

use WHMCS\Database\Capsule;

class DomainDataManager
{
    private const TABLE = 'mod_getyourbd_domain_data';
    private const FIELD_NAMES = [
        'nid' => ['NID', 'GetYourBD NID'],
        'mobile' => ['Mobile Number', 'GetYourBD Contact Number'],
        'nid_document' => ['NID Document', 'GetYourBD NID Document'],
        'registration_document' => ['Registration Document', 'GetYourBD Registration Document'],
    ];

    public static function ensureTable(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->integer('domain_id')->unsigned()->unique();
            $table->string('domain', 255)->index();
            $table->string('nid', 32);
            $table->string('mobile', 32);
            $table->string('nid_document', 255);
            $table->string('registration_document', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public static function captureConfiguration(array $request): array
    {
        $domainFields = $request['domainfield'] ?? [];
        if (!is_array($domainFields)) {
            return [];
        }

        $errors = [];
        foreach ($domainFields as $index => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            $data = self::extract($fields);
            if (!self::containsGetYourBdFields($data)) {
                continue;
            }

            if (!preg_match('/^(\d{10}|\d{13}|\d{17})$/', $data['nid'])) {
                $errors[] = 'NID must be 10, 13, or 17 digits.';
            }
            if (!preg_match('/^\+880\d{10}$/', self::normalisePhone($data['mobile']))) {
                $errors[] = 'Mobile Number must be in +880XXXXXXXXXX format.';
            }
            if (strpos($data['nid_document'], UploadManager::TOKEN_PREFIX) !== 0) {
                $errors[] = 'Please upload the NID Document.';
            }

            $data['mobile'] = self::normalisePhone($data['mobile']);
            $data['domain'] = (string) ($_SESSION['cart']['domains'][$index]['domain'] ?? '');
            $_SESSION['getyourbd_domain_data'][(string) $index] = $data;
        }

        return array_values(array_unique($errors));
    }

    public static function bindCheckout(array $domainIds): void
    {
        self::ensureTable();
        $pending = is_array($_SESSION['getyourbd_domain_data'] ?? null)
            ? $_SESSION['getyourbd_domain_data']
            : [];

        foreach (array_values($domainIds) as $index => $domainId) {
            $domainId = (int) $domainId;
            $domain = Capsule::table('tbldomains')->where('id', $domainId)->first(['domain']);
            if (!$domain) {
                continue;
            }

            $storedFields = Capsule::table('tbldomainsadditionalfields')
                ->where('domainid', $domainId)
                ->pluck('value', 'name')
                ->all();
            $data = self::extract((array) $storedFields);

            if (!self::containsGetYourBdFields($data)) {
                $data = self::pendingForDomain($pending, (string) $domain->domain, $index);
            }
            if (!$data || $data['nid'] === '' || $data['mobile'] === '') {
                continue;
            }

            self::upsert($domainId, (string) $domain->domain, $data);
            self::upsertWhmcsFields($domainId, $data);
            UploadManager::bindToDomain($data['nid_document'], $domainId);
            UploadManager::bindToDomain($data['registration_document'], $domainId);
        }

        unset($_SESSION['getyourbd_domain_data']);
    }

    public static function enrichRegistrarParams(array $params): array
    {
        $domainId = (int) ($params['domainid'] ?? 0);
        if (!$domainId) {
            return $params;
        }

        self::ensureTable();
        $row = Capsule::table(self::TABLE)->where('domain_id', $domainId)->first();
        if (!$row) {
            return $params;
        }

        $additional = is_array($params['additionalfields'] ?? null) ? $params['additionalfields'] : [];
        $additional['NID'] = (string) $row->nid;
        $additional['Mobile Number'] = (string) $row->mobile;
        $additional['NID Document'] = (string) $row->nid_document;
        $additional['Registration Document'] = (string) ($row->registration_document ?? '');
        $params['additionalfields'] = $additional;

        return $params;
    }

    private static function extract(array $fields): array
    {
        $data = [];
        foreach (self::FIELD_NAMES as $key => $names) {
            $data[$key] = '';
            foreach ($names as $name) {
                if (array_key_exists($name, $fields)) {
                    $data[$key] = trim((string) $fields[$name]);
                    break;
                }
            }
        }

        return $data;
    }

    private static function containsGetYourBdFields(array $data): bool
    {
        return $data['nid'] !== '' || $data['mobile'] !== '' || $data['nid_document'] !== '';
    }

    private static function pendingForDomain(array $pending, string $domain, int $index): array
    {
        foreach ($pending as $data) {
            if (!empty($data['domain']) && strcasecmp((string) $data['domain'], $domain) === 0) {
                return $data;
            }
        }

        return $pending[(string) $index] ?? [];
    }

    private static function upsert(int $domainId, string $domain, array $data): void
    {
        $values = [
            'domain' => $domain,
            'nid' => $data['nid'],
            'mobile' => $data['mobile'],
            'nid_document' => $data['nid_document'],
            'registration_document' => $data['registration_document'] ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $existing = Capsule::table(self::TABLE)->where('domain_id', $domainId)->first(['id']);
        if ($existing) {
            Capsule::table(self::TABLE)->where('id', $existing->id)->update($values);
            return;
        }

        $values['domain_id'] = $domainId;
        $values['created_at'] = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE)->insert($values);
    }

    private static function upsertWhmcsFields(int $domainId, array $data): void
    {
        $fields = [
            'NID' => $data['nid'],
            'Mobile Number' => $data['mobile'],
            'NID Document' => $data['nid_document'],
            'Registration Document' => $data['registration_document'],
        ];
        foreach ($fields as $name => $value) {
            $existing = Capsule::table('tbldomainsadditionalfields')
                ->where('domainid', $domainId)
                ->where('name', $name)
                ->first(['id']);
            if ($existing) {
                Capsule::table('tbldomainsadditionalfields')->where('id', $existing->id)->update(['value' => $value]);
            } else {
                Capsule::table('tbldomainsadditionalfields')->insert([
                    'domainid' => $domainId,
                    'name' => $name,
                    'value' => $value,
                ]);
            }
        }
    }

    private static function normalisePhone(string $phone): string
    {
        $phone = str_replace([' ', '-', '(', ')', '.'], '', trim($phone));
        if (strpos($phone, '880') === 0) {
            return '+' . $phone;
        }
        if (strpos($phone, '0') === 0 && strlen($phone) === 11) {
            return '+88' . $phone;
        }

        return $phone;
    }
}
