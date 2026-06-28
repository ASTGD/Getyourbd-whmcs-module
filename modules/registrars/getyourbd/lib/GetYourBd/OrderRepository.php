<?php

namespace GetYourBd;

use WHMCS\Database\Capsule;

class OrderRepository
{
    public static function recordSuccess(array $params, array $payload, array $response): void
    {
        if (!class_exists(Capsule::class)) {
            return;
        }

        Installer::ensureOrderTable();

        $domainId = isset($params['domainid']) ? (int) $params['domainid'] : null;
        $domain = (string) ($payload['domain'] ?? FieldExtractor::buildDomainName($params));
        $order = is_array($response['order'] ?? null) ? $response['order'] : [];
        $invoice = is_array($response['invoice'] ?? null) ? $response['invoice'] : [];
        $pricing = is_array($response['pricing'] ?? null) ? $response['pricing'] : [];
        $now = date('Y-m-d H:i:s');

        $data = [
            'domainid' => $domainId,
            'domain' => $domain,
            'partner_order_id' => isset($order['id']) ? (string) $order['id'] : null,
            'partner_invoice_id' => isset($invoice['id']) ? (string) $invoice['id'] : null,
            'partner_invoice_status' => isset($invoice['status']) ? (string) $invoice['status'] : null,
            'partner_invoice_total' => isset($invoice['total']) ? (float) $invoice['total'] : null,
            'status' => isset($order['status']) ? (string) $order['status'] : null,
            'registration_status' => isset($order['registration_status'])
                ? (string) $order['registration_status']
                : null,
            'pricing_json' => $pricing ? json_encode($pricing) : null,
            'response_json' => json_encode($response),
            'updated_at' => $now,
        ];

        $query = Capsule::table('mod_getyourbd_orders');
        if ($domainId) {
            $query = $query->where('domainid', $domainId);
        } else {
            $query = $query->where('domain', $domain);
        }

        $existing = $query->first();
        if ($existing) {
            Capsule::table('mod_getyourbd_orders')->where('id', $existing->id)->update($data);
        } else {
            $data['created_at'] = $now;
            Capsule::table('mod_getyourbd_orders')->insert($data);
        }

        self::appendDomainNote($domainId, $response);
        self::saveNameserversLocally($params, (array) ($payload['nameServers'] ?? []));
    }

    public static function recordNameserverUpdate(array $params, array $payload, array $response): void
    {
        if (!class_exists(Capsule::class)) {
            return;
        }

        Installer::ensureOrderTable();

        $domainId = isset($params['domainid']) ? (int) $params['domainid'] : null;
        $domain = (string) ($payload['domain'] ?? FieldExtractor::buildDomainName($params));
        $order = is_array($response['order'] ?? null) ? $response['order'] : [];
        $now = date('Y-m-d H:i:s');

        $data = [
            'domainid' => $domainId,
            'domain' => $domain,
            'updated_at' => $now,
        ];
        if (isset($order['status'])) {
            $data['status'] = (string) $order['status'];
        }
        if (isset($order['id'])) {
            $data['partner_order_id'] = (string) $order['id'];
        }

        $query = Capsule::table('mod_getyourbd_orders');
        if ($domainId) {
            $query = $query->where('domainid', $domainId);
        } else {
            $query = $query->where('domain', $domain);
        }

        $existing = $query->first();
        if ($existing) {
            Capsule::table('mod_getyourbd_orders')->where('id', $existing->id)->update($data);
        } else {
            $data['created_at'] = $now;
            Capsule::table('mod_getyourbd_orders')->insert($data);
        }

        self::saveNameserversLocally($params, (array) ($payload['nameServers'] ?? []));
    }

    public static function isPendingDomain(array $params): bool
    {
        $status = trim((string) ($params['status'] ?? ''));

        if (class_exists(Capsule::class) && !empty($params['domainid'])) {
            $schema = Capsule::schema();
            if ($schema->hasTable('tbldomains') && $schema->hasColumn('tbldomains', 'status')) {
                $storedStatus = Capsule::table('tbldomains')
                    ->where('id', (int) $params['domainid'])
                    ->value('status');

                if ($storedStatus !== null) {
                    $status = trim((string) $storedStatus);
                }
            }
        }

        return strcasecmp($status, 'Pending') === 0;
    }

    public static function saveNameserversLocally(array $params, array $nameservers): void
    {
        $domainId = isset($params['domainid']) ? (int) $params['domainid'] : null;
        self::updateWhmcsNameservers($domainId, $nameservers);
    }

    public static function currentNameservers(array $params): array
    {
        $nameservers = [];
        for ($index = 1; $index <= 5; $index++) {
            $value = trim((string) ($params['ns' . $index] ?? ''));
            if ($value !== '') {
                $nameservers['ns' . $index] = $value;
            }
        }

        if ($nameservers || !class_exists(Capsule::class) || empty($params['domainid'])) {
            return $nameservers;
        }

        $schema = Capsule::schema();
        if (!$schema->hasTable('tbldomains')) {
            return [];
        }

        $columns = [];
        for ($index = 1; $index <= 5; $index++) {
            $column = 'nameserver' . $index;
            if ($schema->hasColumn('tbldomains', $column)) {
                $columns[] = $column;
            }
        }

        if (!$columns) {
            return [];
        }

        $domain = Capsule::table('tbldomains')->where('id', (int) $params['domainid'])->first($columns);
        if (!$domain) {
            return [];
        }

        for ($index = 1; $index <= 5; $index++) {
            $value = trim((string) ($domain->{'nameserver' . $index} ?? ''));
            if ($value !== '') {
                $nameservers['ns' . $index] = $value;
            }
        }

        return $nameservers;
    }

    private static function updateWhmcsNameservers(?int $domainId, array $nameservers): void
    {
        if (!$domainId || !class_exists(Capsule::class)) {
            return;
        }

        $schema = Capsule::schema();
        if (!$schema->hasTable('tbldomains')) {
            return;
        }

        $data = [];
        for ($index = 1; $index <= 5; $index++) {
            $column = 'nameserver' . $index;
            if (!$schema->hasColumn('tbldomains', $column)) {
                continue;
            }

            $data[$column] = isset($nameservers[$index - 1]) ? (string) $nameservers[$index - 1] : '';
        }

        if ($data) {
            Capsule::table('tbldomains')->where('id', $domainId)->update($data);
        }
    }

    private static function appendDomainNote(?int $domainId, array $response): void
    {
        if (!$domainId) {
            return;
        }

        $order = is_array($response['order'] ?? null) ? $response['order'] : [];
        $invoice = is_array($response['invoice'] ?? null) ? $response['invoice'] : [];
        $orderId = isset($order['id']) ? (string) $order['id'] : '';
        $invoiceId = isset($invoice['id']) ? (string) $invoice['id'] : '';

        if ($orderId === '' && $invoiceId === '') {
            return;
        }

        $domain = Capsule::table('tbldomains')->where('id', $domainId)->first(['additionalnotes']);
        if (!$domain) {
            return;
        }

        $line = 'GetYourBD partner order';
        if ($orderId !== '') {
            $line .= ' #' . $orderId;
        }
        if ($invoiceId !== '') {
            $line .= ', invoice #' . $invoiceId;
        }

        $notes = trim((string) ($domain->additionalnotes ?? ''));
        if (strpos($notes, $line) !== false) {
            return;
        }

        $notes = $notes === '' ? $line : $notes . "\n" . $line;
        Capsule::table('tbldomains')->where('id', $domainId)->update(['additionalnotes' => $notes]);
    }
}
