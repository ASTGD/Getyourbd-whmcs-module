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
