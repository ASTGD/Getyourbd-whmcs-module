<?php

use GetYourBd\Installer;
use GetYourBd\PricingManager;
use GetYourBd\UploadManager;
use GetYourBd\DomainDataManager;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function getyourbd_addon_bootstrap(): void
{
    require_once ROOTDIR . '/modules/registrars/getyourbd/lib/bootstrap.php';
}

function getyourbd_config()
{
    return [
        'name' => 'GetYourBD .bd Domain Integration',
        'description' => 'Adds .bd WHOIS lookup support and checkout fields for the GetYourBD registrar module.',
        'author' => 'ASTGD',
        'language' => 'english',
        'version' => '1.1.5',
        'fields' => [],
    ];
}

function getyourbd_activate()
{
    try {
        getyourbd_addon_bootstrap();

        PricingManager::assertRequiredCurrenciesExist();
        $whois = Installer::ensureWhoisEntry(ROOTDIR);
        $fields = Installer::ensureAdditionalFieldsInclude(ROOTDIR);
        Installer::ensureOrderTable();
        UploadManager::ensureTable();
        DomainDataManager::ensureTable();
        PricingManager::ensureTable();
        $pricing = PricingManager::syncAndApply();

        return [
            'status' => 'success',
            'description' => $whois['message'] . ' ' . $fields['message'] . ' ' . $pricing['message'],
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'description' => $e->getMessage(),
        ];
    }
}

function getyourbd_deactivate()
{
    try {
        getyourbd_addon_bootstrap();

        $whois = Installer::removeWhoisEntry(ROOTDIR);
        $fields = Installer::removeAdditionalFieldsInclude(ROOTDIR);

        return [
            'status' => 'success',
            'description' => $whois['message'] . ' ' . $fields['message'],
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'description' => $e->getMessage(),
        ];
    }
}

function getyourbd_uninstall()
{
    return getyourbd_deactivate();
}

function getyourbd_upgrade($vars): void
{
    getyourbd_addon_bootstrap();
    Installer::ensureOrderTable();
    UploadManager::ensureTable();
    DomainDataManager::ensureTable();
    PricingManager::ensureTable();
    Installer::ensureWhoisEntry(ROOTDIR);
    Installer::ensureAdditionalFieldsInclude(ROOTDIR);
    PricingManager::syncAndApply();
}

function getyourbd_output($vars): void
{
    getyourbd_addon_bootstrap();
    PricingManager::ensureTable();

    $notice = getyourbd_handle_pricing_action();

    $whoisPath = Installer::whoisPath(ROOTDIR);
    $additionalFieldsPath = Installer::additionalFieldsPath(ROOTDIR);

    $whoisInstalled = false;
    if (is_readable($whoisPath)) {
        $entries = json_decode((string) file_get_contents($whoisPath), true);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (($entry['uri'] ?? '') === Installer::WHOIS_URI) {
                    $whoisInstalled = true;
                    break;
                }
            }
        }
    }

    $fieldsInstalled = is_readable($additionalFieldsPath)
        && strpos((string) file_get_contents($additionalFieldsPath), 'GetYourBD additional domain fields') !== false;
    $pricingInstalled = getyourbd_domain_pricing_installed();

    echo '<h2>GetYourBD .bd Domain Integration</h2>';
    if ($notice !== '') {
        echo $notice;
    }
    echo '<p>This addon manages the local WHMCS domain lookup resources. Configure partner credentials under ';
    echo '<strong>System Settings &gt; Domain Registrars &gt; GetYourBD Partner API</strong>.</p>';
    echo '<table class="table table-striped" style="max-width: 900px">';
    echo '<tbody>';
    echo getyourbd_status_row('WHOIS entry', $whoisInstalled, $whoisPath);
    echo getyourbd_status_row('Additional domain fields', $fieldsInstalled, $additionalFieldsPath);
    echo getyourbd_status_row('BDT and USD domain pricing', $pricingInstalled, 'tbldomainpricing / tblpricing');
    echo '</tbody>';
    echo '</table>';

    getyourbd_render_pricing_table();
}

function getyourbd_status_row(string $label, bool $installed, string $path): string
{
    $badge = $installed
        ? '<span class="label label-success">Installed</span>'
        : '<span class="label label-warning">Missing</span>';

    return '<tr><th style="width: 240px">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>'
        . '<td>' . $badge . '</td>'
        . '<td><code>' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</code></td></tr>';
}

function getyourbd_domain_pricing_installed(): bool
{
    if (!class_exists(\WHMCS\Database\Capsule::class)) {
        return false;
    }

    $bdt = \WHMCS\Database\Capsule::table('tblcurrencies')->where('code', 'BDT')->first(['id']);
    $usd = \WHMCS\Database\Capsule::table('tblcurrencies')->where('code', 'USD')->first(['id']);

    if (!$bdt || !$usd) {
        return false;
    }

    foreach (PricingManager::pricedTlds() as $extension) {
        $domainPricing = \WHMCS\Database\Capsule::table('tbldomainpricing')
            ->where('extension', $extension)
            ->where('autoreg', 'getyourbd')
            ->first(['id']);
        if (!$domainPricing) {
            return false;
        }

        foreach ([(int) $bdt->id, (int) $usd->id] as $currencyId) {
            foreach (['domainregister', 'domainrenew', 'domaintransfer'] as $type) {
                $exists = \WHMCS\Database\Capsule::table('tblpricing')
                    ->where('type', $type)
                    ->where('currency', $currencyId)
                    ->where('relid', (int) $domainPricing->id)
                    ->exists();
                if (!$exists) {
                    return false;
                }
            }
        }
    }

    return true;
}

function getyourbd_handle_pricing_action(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['getyourbd_action'])) {
        return '';
    }

    if (function_exists('check_token')) {
        check_token('WHMCS.admin.default');
    }

    try {
        $action = (string) $_POST['getyourbd_action'];
        if ($action === 'sync') {
            $result = PricingManager::syncFromApis();
        } elseif ($action === 'apply') {
            $result = PricingManager::applyStoredPrices();
        } elseif ($action === 'save') {
            $result = PricingManager::saveOverrides($_POST['prices'] ?? []);
        } else {
            throw new RuntimeException('Unknown GetYourBD pricing action.');
        }

        return '<div class="alert alert-success">' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
    } catch (Throwable $e) {
        return '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

function getyourbd_render_pricing_table(): void
{
    $rows = PricingManager::rows();
    $token = function_exists('generate_token') ? generate_token('plain') : '';

    echo '<h3>TLD Pricing</h3>';
    echo '<p>Sync pulls BDT pricing from GetYourBD and calculates USD using the ExchangeRate API. Save edits before applying manual prices to WHMCS.</p>';
    echo '<form method="post" style="margin-bottom:15px">';
    echo $token;
    echo '<button type="submit" name="getyourbd_action" value="sync" class="btn btn-default">Sync from GetYourBD API</button> ';
    echo '<button type="submit" name="getyourbd_action" value="apply" class="btn btn-primary">Apply Prices to WHMCS</button>';
    echo '</form>';

    if (!$rows) {
        echo '<div class="alert alert-info">No TLD prices are stored yet. Use Sync from GetYourBD API.</div>';
        return;
    }

    echo '<form method="post">';
    echo $token;
    echo '<input type="hidden" name="getyourbd_action" value="save">';
    echo '<table class="table table-striped table-bordered" style="max-width:1200px">';
    echo '<thead><tr>'
        . '<th>TLD</th>'
        . '<th>API Register BDT</th>'
        . '<th>API Renew BDT</th>'
        . '<th>Register BDT</th>'
        . '<th>Renew BDT</th>'
        . '<th>Register USD</th>'
        . '<th>Renew USD</th>'
        . '<th>Manual</th>'
        . '<th>Updated</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $tld = (string) $row->tld;
        echo '<tr>';
        echo '<td><strong>.' . htmlspecialchars($tld, ENT_QUOTES, 'UTF-8') . '</strong></td>';
        echo '<td>' . htmlspecialchars(number_format((float) $row->api_register_bdt, 2, '.', ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars(number_format((float) $row->api_renew_bdt, 2, '.', ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo getyourbd_price_input($tld, 'register_bdt', $row->register_bdt);
        echo getyourbd_price_input($tld, 'renew_bdt', $row->renew_bdt);
        echo getyourbd_price_input($tld, 'register_usd', $row->register_usd);
        echo getyourbd_price_input($tld, 'renew_usd', $row->renew_usd);
        echo '<td>' . ((int) $row->manual_override === 1 ? 'Yes' : 'No') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->updated_at, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<button type="submit" class="btn btn-success">Save Manual Overrides</button>';
    echo '</form>';
}

function getyourbd_price_input(string $tld, string $field, $value): string
{
    $name = 'prices[' . htmlspecialchars($tld, ENT_QUOTES, 'UTF-8') . '][' . $field . ']';
    $value = htmlspecialchars(number_format((float) $value, 2, '.', ''), ENT_QUOTES, 'UTF-8');

    return '<td><input class="form-control input-sm" type="number" step="0.01" min="0.01" name="' . $name . '" value="' . $value . '"></td>';
}
