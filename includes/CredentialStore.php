<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\WordPressEncryptedOption;

defined('ABSPATH') || exit;

final class CredentialStore
{
    public const OPTION_KEY = 'paymos_woocommerce_credentials_v1';
    private const AAD = 'paymos-for-woocommerce-credentials-v1';

    /**
     * @return array<string, array<string, string>>
     */
    public static function load()
    {
        $decoded = WordPressEncryptedOption::load(self::OPTION_KEY, self::AAD);
        if (count($decoded) === 0) {
            return array();
        }
        if (!is_array($decoded) || !isset($decoded['schema'], $decoded['environments']) || (int) $decoded['schema'] !== 1 || !is_array($decoded['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }

        return CredentialValidator::normalize($decoded['environments']);
    }

    /**
     * @param array<string, mixed> $environments
     */
    public static function save(array $environments)
    {
        $normalized = CredentialValidator::normalize($environments);
        if (count($normalized) === 0) {
            WordPressEncryptedOption::delete(self::OPTION_KEY);
            return;
        }
        WordPressEncryptedOption::save(self::OPTION_KEY, self::AAD, array(
            'schema' => 1,
            'environments' => $normalized,
        ));
    }

    public static function clear()
    {
        WordPressEncryptedOption::delete(self::OPTION_KEY);
    }

    public static function keyMaterial()
    {
        return WordPressEncryptedOption::keyMaterial();
    }
}
