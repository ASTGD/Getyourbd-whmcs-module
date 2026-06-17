<?php

use GetYourBd\Installer;

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
        'version' => '1.0.0',
        'fields' => [],
    ];
}

function getyourbd_activate()
{
    try {
        getyourbd_addon_bootstrap();

        $whois = Installer::ensureWhoisEntry(ROOTDIR);
        $fields = Installer::ensureAdditionalFieldsInclude(ROOTDIR);
        Installer::ensureOrderTable();

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
    Installer::ensureWhoisEntry(ROOTDIR);
    Installer::ensureAdditionalFieldsInclude(ROOTDIR);
}

function getyourbd_output($vars): void
{
    getyourbd_addon_bootstrap();

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

    echo '<h2>GetYourBD .bd Domain Integration</h2>';
    echo '<p>This addon manages the local WHMCS domain lookup resources. Configure partner credentials under ';
    echo '<strong>System Settings &gt; Domain Registrars &gt; GetYourBD Partner API</strong>.</p>';
    echo '<table class="table table-striped" style="max-width: 900px">';
    echo '<tbody>';
    echo getyourbd_status_row('WHOIS entry', $whoisInstalled, $whoisPath);
    echo getyourbd_status_row('Additional domain fields', $fieldsInstalled, $additionalFieldsPath);
    echo '</tbody>';
    echo '</table>';
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
