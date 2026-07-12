<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\CredentialSet;

defined('ABSPATH') || exit;

final class CredentialValidator
{
    public const DEFAULT_BASE_URL = CredentialSet::DEFAULT_BASE_URL;

    /**
     * @param array<string, mixed> $source
     * @return array<string, array<string, string>>
     */
    public static function normalize(array $source)
    {
        return CredentialSet::normalize($source);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function normalizeEnvironment($environment, array $values)
    {
        return CredentialSet::normalizeEnvironment($environment, $values);
    }
}
