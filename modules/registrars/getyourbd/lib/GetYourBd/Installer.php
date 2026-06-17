<?php

namespace GetYourBd;

use RuntimeException;
use WHMCS\Database\Capsule;

class Installer
{
    public const WHOIS_EXTENSIONS = '.bd,.com.bd,.net.bd,.org.bd,.edu.bd,.ac.bd,.gov.bd,.mil.bd,.info.bd,.বাংলা';
    public const WHOIS_URI = 'socket://13.214.45.11:1043';
    public const WHOIS_AVAILABLE = 'Domain available';

    private const ADDITIONAL_FIELDS_START = '// BEGIN GetYourBD additional domain fields';
    private const ADDITIONAL_FIELDS_END = '// END GetYourBD additional domain fields';

    public static function ensureWhoisEntry(string $rootDir): array
    {
        $path = self::whoisPath($rootDir);
        $entries = self::readWhoisEntries($path);

        foreach ($entries as $entry) {
            if (self::hasWhoisCoverage($entry)) {
                return [
                    'changed' => false,
                    'path' => $path,
                    'message' => 'GetYourBD WHOIS entry already exists.',
                ];
            }
        }

        $entries[] = self::whoisEntry();
        self::writeWhoisEntries($path, $entries);

        return [
            'changed' => true,
            'path' => $path,
            'message' => 'GetYourBD WHOIS entry added.',
        ];
    }

    public static function removeWhoisEntry(string $rootDir): array
    {
        $path = self::whoisPath($rootDir);
        $entries = self::readWhoisEntries($path);
        $kept = [];
        $removed = 0;

        foreach ($entries as $entry) {
            if (self::isWhoisEntryMatch($entry)) {
                $removed++;
                continue;
            }

            $kept[] = $entry;
        }

        if ($removed > 0) {
            self::writeWhoisEntries($path, $kept);
        }

        return [
            'changed' => $removed > 0,
            'path' => $path,
            'message' => $removed > 0
                ? 'GetYourBD WHOIS entry removed.'
                : 'GetYourBD WHOIS entry was not present.',
        ];
    }

    public static function ensureAdditionalFieldsInclude(string $rootDir): array
    {
        $path = self::additionalFieldsPath($rootDir);
        self::ensureDirectory(dirname($path));

        if (!file_exists($path)) {
            file_put_contents($path, "<?php\n\n");
        }

        $contents = (string) file_get_contents($path);
        if (strpos($contents, self::ADDITIONAL_FIELDS_START) !== false) {
            return [
                'changed' => false,
                'path' => $path,
                'message' => 'GetYourBD additional fields include already exists.',
            ];
        }

        if (trim($contents) === '') {
            $contents = "<?php\n\n";
        }

        if (!preg_match('/^\s*<\?php\b/', $contents)) {
            throw new RuntimeException(
                'resources/domains/additionalfields.php exists but does not start with <?php. '
                . 'Fix the file before enabling automatic additional domain fields.'
            );
        }

        $block = "\n" . self::ADDITIONAL_FIELDS_START . "\n"
            . "include_once ROOTDIR . '/modules/registrars/getyourbd/lib/additionalfields.php';\n"
            . self::ADDITIONAL_FIELDS_END . "\n";

        file_put_contents($path, rtrim($contents) . "\n" . $block);

        return [
            'changed' => true,
            'path' => $path,
            'message' => 'GetYourBD additional fields include added.',
        ];
    }

    public static function removeAdditionalFieldsInclude(string $rootDir): array
    {
        $path = self::additionalFieldsPath($rootDir);
        if (!file_exists($path)) {
            return [
                'changed' => false,
                'path' => $path,
                'message' => 'Custom additionalfields.php does not exist.',
            ];
        }

        $contents = (string) file_get_contents($path);
        $pattern = '/\R?' . preg_quote(self::ADDITIONAL_FIELDS_START, '/') . '.*?'
            . preg_quote(self::ADDITIONAL_FIELDS_END, '/') . '\R?/s';
        $updated = preg_replace($pattern, "\n", $contents, -1, $count);

        if ($count > 0) {
            file_put_contents($path, rtrim((string) $updated) . "\n");
        }

        return [
            'changed' => $count > 0,
            'path' => $path,
            'message' => $count > 0
                ? 'GetYourBD additional fields include removed.'
                : 'GetYourBD additional fields include was not present.',
        ];
    }

    public static function ensureOrderTable(): void
    {
        if (!class_exists(Capsule::class)) {
            return;
        }

        $schema = Capsule::schema();
        if ($schema->hasTable('mod_getyourbd_orders')) {
            return;
        }

        $schema->create('mod_getyourbd_orders', function ($table) {
            $table->increments('id');
            $table->integer('domainid')->unsigned()->nullable()->index();
            $table->string('domain', 255)->index();
            $table->string('partner_order_id', 64)->nullable();
            $table->string('partner_invoice_id', 64)->nullable();
            $table->string('partner_invoice_status', 64)->nullable();
            $table->decimal('partner_invoice_total', 16, 2)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('registration_status', 64)->nullable();
            $table->text('pricing_json')->nullable();
            $table->mediumText('response_json')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public static function whoisEntry(): array
    {
        return [
            'extensions' => self::WHOIS_EXTENSIONS,
            'uri' => self::WHOIS_URI,
            'available' => self::WHOIS_AVAILABLE,
        ];
    }

    public static function whoisPath(string $rootDir): string
    {
        return rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'domains' . DIRECTORY_SEPARATOR . 'whois.json';
    }

    public static function additionalFieldsPath(string $rootDir): string
    {
        return rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'domains' . DIRECTORY_SEPARATOR . 'additionalfields.php';
    }

    private static function readWhoisEntries(string $path): array
    {
        if (!file_exists($path) || trim((string) file_get_contents($path)) === '') {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Unable to parse resources/domains/whois.json. '
                . 'Please fix the JSON syntax before activating GetYourBD.'
            );
        }

        return array_values($decoded);
    }

    private static function writeWhoisEntries(string $path, array $entries): void
    {
        self::ensureDirectory(dirname($path));

        $json = json_encode(
            array_values($entries),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('Unable to encode WHOIS configuration as JSON.');
        }

        file_put_contents($path, $json . "\n");
    }

    private static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private static function isWhoisEntryMatch($entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        $uri = isset($entry['uri']) ? (string) $entry['uri'] : '';
        $available = isset($entry['available']) ? (string) $entry['available'] : '';
        $extensions = isset($entry['extensions']) ? (string) $entry['extensions'] : '';

        return strcasecmp($uri, self::WHOIS_URI) === 0
            && strcasecmp($available, self::WHOIS_AVAILABLE) === 0
            && self::normaliseExtensions($extensions) === self::normaliseExtensions(self::WHOIS_EXTENSIONS);
    }

    private static function hasWhoisCoverage($entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        $uri = isset($entry['uri']) ? (string) $entry['uri'] : '';
        $available = isset($entry['available']) ? (string) $entry['available'] : '';
        $extensions = isset($entry['extensions']) ? (string) $entry['extensions'] : '';

        if (strcasecmp($uri, self::WHOIS_URI) !== 0 || strcasecmp($available, self::WHOIS_AVAILABLE) !== 0) {
            return false;
        }

        $existing = self::normaliseExtensions($extensions);
        foreach (self::normaliseExtensions(self::WHOIS_EXTENSIONS) as $required) {
            if (!in_array($required, $existing, true)) {
                return false;
            }
        }

        return true;
    }

    private static function normaliseExtensions(string $extensions): array
    {
        $parts = array_filter(array_map('trim', explode(',', self::lower($extensions))));
        sort($parts);

        return $parts;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
