<?php

namespace GetYourBd;

use RuntimeException;
use WHMCS\Database\Capsule;

class PricingManager
{
    private const TLD_PRICE_URL = 'https://getyour.com.bd/api/v1/tld-prices';
    private const EXCHANGE_RATE_URL = 'https://v6.exchangerate-api.com/v6/240281cd706e38e31942a3f6/latest/USD';
    private const TABLE = 'mod_getyourbd_tld_prices';
    private const EXCLUDED_TLDS = ['ac.bd', 'gov.bd', 'mil.bd'];
    private const PRICING_TERM_COLUMNS = [
        1 => 'msetupfee',
        2 => 'qsetupfee',
        3 => 'ssetupfee',
        4 => 'asetupfee',
        5 => 'bsetupfee',
    ];
    private const ALL_DOMAIN_TERM_COLUMNS = [
        'msetupfee',
        'qsetupfee',
        'ssetupfee',
        'asetupfee',
        'bsetupfee',
        'monthly',
        'quarterly',
        'semiannually',
        'annually',
        'biennially',
    ];

    public static function ensureTable(): void
    {
        if (!class_exists(Capsule::class)) {
            return;
        }

        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->string('tld', 64)->unique();
            $table->decimal('api_register_bdt', 16, 2)->nullable();
            $table->decimal('api_renew_bdt', 16, 2)->nullable();
            $table->decimal('register_bdt', 16, 2)->nullable();
            $table->decimal('renew_bdt', 16, 2)->nullable();
            $table->decimal('register_usd', 16, 2)->nullable();
            $table->decimal('renew_usd', 16, 2)->nullable();
            $table->boolean('manual_override')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public static function assertRequiredCurrenciesExist(): void
    {
        foreach (['BDT', 'USD'] as $code) {
            if (!self::currency($code)) {
                throw new RuntimeException(
                    $code . ' currency is required before activating GetYourBD pricing. '
                    . 'Add ' . $code . ' under System Settings > Currencies, then activate this addon again.'
                );
            }
        }
    }

    public static function syncFromApis(): array
    {
        self::ensureTable();
        self::assertRequiredCurrenciesExist();

        $apiPrices = self::fetchTldPrices();
        $bdtPerUsd = self::fetchBdtPerUsd();
        $now = date('Y-m-d H:i:s');
        $synced = 0;

        foreach ($apiPrices as $price) {
            $tld = self::normaliseTld((string) ($price['tld'] ?? ''));
            if ($tld === '' || in_array($tld, self::EXCLUDED_TLDS, true)) {
                continue;
            }

            $registerBdt = self::money((float) ($price['registration_price'] ?? 0));
            $renewBdt = self::money((float) ($price['renewal_price'] ?? 0));
            if ((float) $registerBdt <= 0 || (float) $renewBdt <= 0) {
                continue;
            }

            $existing = Capsule::table(self::TABLE)->where('tld', $tld)->first();
            $manualOverride = $existing && (int) ($existing->manual_override ?? 0) === 1;

            $data = [
                'api_register_bdt' => $registerBdt,
                'api_renew_bdt' => $renewBdt,
                'updated_at' => $now,
            ];

            if (!$manualOverride) {
                $data['register_bdt'] = $registerBdt;
                $data['renew_bdt'] = $renewBdt;
                $data['register_usd'] = self::money((float) $registerBdt / $bdtPerUsd);
                $data['renew_usd'] = self::money((float) $renewBdt / $bdtPerUsd);
                $data['manual_override'] = 0;
            }

            if ($existing) {
                Capsule::table(self::TABLE)->where('id', $existing->id)->update($data);
            } else {
                $data['tld'] = $tld;
                $data['created_at'] = $now;
                Capsule::table(self::TABLE)->insert($data);
            }

            $synced++;
        }

        return [
            'count' => $synced,
            'bdtPerUsd' => self::money($bdtPerUsd),
            'message' => sprintf('Synced %d TLD prices from GetYourBD. USD used 1 USD = %s BDT.', $synced, self::money($bdtPerUsd)),
        ];
    }

    public static function saveOverrides(array $prices): array
    {
        self::ensureTable();
        $updated = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($prices as $tld => $values) {
            $tld = self::normaliseTld((string) $tld);
            if ($tld === '' || !is_array($values)) {
                continue;
            }

            $data = [
                'register_bdt' => self::money((float) ($values['register_bdt'] ?? 0)),
                'renew_bdt' => self::money((float) ($values['renew_bdt'] ?? 0)),
                'register_usd' => self::money((float) ($values['register_usd'] ?? 0)),
                'renew_usd' => self::money((float) ($values['renew_usd'] ?? 0)),
                'manual_override' => 1,
                'updated_at' => $now,
            ];

            foreach (['register_bdt', 'renew_bdt', 'register_usd', 'renew_usd'] as $field) {
                if ((float) $data[$field] <= 0) {
                    throw new RuntimeException('All override prices must be greater than zero for ' . $tld . '.');
                }
            }

            $existing = Capsule::table(self::TABLE)->where('tld', $tld)->first(['id']);
            if (!$existing) {
                $data['tld'] = $tld;
                $data['created_at'] = $now;
                Capsule::table(self::TABLE)->insert($data);
            } else {
                Capsule::table(self::TABLE)->where('id', $existing->id)->update($data);
            }

            $updated++;
        }

        return [
            'count' => $updated,
            'message' => 'Saved manual pricing overrides for ' . $updated . ' TLDs.',
        ];
    }

    public static function applyStoredPrices(): array
    {
        self::ensureTable();
        self::assertRequiredCurrenciesExist();

        $bdtCurrencyId = (int) self::currency('BDT')->id;
        $usdCurrencyId = (int) self::currency('USD')->id;
        $rows = self::rows();
        $applied = 0;

        foreach ($rows as $row) {
            $extension = '.' . ltrim((string) $row->tld, '.');
            $domainPricingId = self::ensureDomainPricing($extension);

            self::upsertPricingRow(
                $domainPricingId,
                $bdtCurrencyId,
                'domainregister',
                self::pricingData((float) $row->register_bdt)
            );
            self::upsertPricingRow(
                $domainPricingId,
                $bdtCurrencyId,
                'domainrenew',
                self::pricingData((float) $row->renew_bdt)
            );
            self::upsertPricingRow(
                $domainPricingId,
                $bdtCurrencyId,
                'domaintransfer',
                self::disabledPricingData()
            );
            self::upsertPricingRow(
                $domainPricingId,
                $usdCurrencyId,
                'domainregister',
                self::pricingData((float) $row->register_usd)
            );
            self::upsertPricingRow(
                $domainPricingId,
                $usdCurrencyId,
                'domainrenew',
                self::pricingData((float) $row->renew_usd)
            );
            self::upsertPricingRow(
                $domainPricingId,
                $usdCurrencyId,
                'domaintransfer',
                self::disabledPricingData()
            );

            $applied++;
        }

        return [
            'count' => $applied,
            'message' => 'Applied BDT and USD WHMCS pricing for ' . $applied . ' TLDs.',
        ];
    }

    public static function syncAndApply(): array
    {
        $sync = self::syncFromApis();
        $apply = self::applyStoredPrices();

        return [
            'message' => $sync['message'] . ' ' . $apply['message'],
        ];
    }

    public static function rows(): array
    {
        self::ensureTable();

        return Capsule::table(self::TABLE)
            ->orderBy('tld')
            ->get()
            ->all();
    }

    public static function pricedTlds(): array
    {
        return array_map(static function ($row) {
            return '.' . ltrim((string) $row->tld, '.');
        }, self::rows());
    }

    private static function fetchTldPrices(): array
    {
        $response = self::getJson(self::TLD_PRICE_URL);
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException('GetYourBD TLD pricing API returned an unexpected response.');
        }

        return $response['data'];
    }

    private static function fetchBdtPerUsd(): float
    {
        $response = self::getJson(self::EXCHANGE_RATE_URL);
        $rate = (float) ($response['conversion_rates']['BDT'] ?? 0);
        if ($rate <= 0) {
            throw new RuntimeException('ExchangeRate API did not return a valid USD to BDT rate.');
        }

        return $rate;
    }

    private static function getJson(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Unable to fetch ' . $url . ': ' . ($error ?: 'HTTP ' . $status));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON returned from ' . $url . '.');
        }

        return $decoded;
    }

    private static function currency(string $code)
    {
        return Capsule::table('tblcurrencies')->where('code', $code)->first(['id']);
    }

    private static function ensureDomainPricing(string $extension): int
    {
        $existing = Capsule::table('tbldomainpricing')->where('extension', $extension)->first(['id']);
        $data = [
            'extension' => $extension,
            'autoreg' => 'getyourbd',
        ];

        if (Capsule::schema()->hasColumn('tbldomainpricing', 'order')) {
            $data['order'] = self::pricingSortOrder($extension);
        }

        if ($existing) {
            Capsule::table('tbldomainpricing')->where('id', $existing->id)->update($data);
            return (int) $existing->id;
        }

        return (int) Capsule::table('tbldomainpricing')->insertGetId($data);
    }

    private static function pricingData(float $yearlyPrice): array
    {
        $data = self::disabledPricingData();
        foreach (self::PRICING_TERM_COLUMNS as $term => $column) {
            $data[$column] = self::money($yearlyPrice * $term);
        }

        return $data;
    }

    private static function disabledPricingData(): array
    {
        $data = [];
        foreach (self::ALL_DOMAIN_TERM_COLUMNS as $column) {
            $data[$column] = '-1.00';
        }

        return $data;
    }

    private static function upsertPricingRow(int $relId, int $currencyId, string $type, array $prices): void
    {
        $existing = Capsule::table('tblpricing')
            ->where('type', $type)
            ->where('currency', $currencyId)
            ->where('relid', $relId)
            ->first(['id']);

        $data = array_merge([
            'type' => $type,
            'currency' => $currencyId,
            'relid' => $relId,
            'tsetupfee' => '0.00',
        ], $prices);

        if ($existing) {
            Capsule::table('tblpricing')->where('id', $existing->id)->update($data);
            return;
        }

        Capsule::table('tblpricing')->insert($data);
    }

    private static function pricingSortOrder(string $extension): int
    {
        $tlds = array_map(static function ($row) {
            return '.' . ltrim((string) $row->tld, '.');
        }, self::rows());
        $index = array_search($extension, $tlds, true);

        return $index === false ? 0 : $index + 1;
    }

    private static function normaliseTld(string $tld): string
    {
        $tld = trim($tld);
        $tld = ltrim($tld, '.');

        return function_exists('mb_strtolower')
            ? mb_strtolower($tld, 'UTF-8')
            : strtolower($tld);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
