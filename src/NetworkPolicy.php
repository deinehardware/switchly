<?php

declare(strict_types=1);

/** Validates outbound targets while keeping RFC1918 switch management usable. */
final class NetworkPolicy
{
    /** Validates a switch host; private LAN ranges are allowed, special ranges are not. */
    public static function requireSwitchHost(string $host): void
    {
        self::requireHost($host, true, 'Switch-Ziel');
    }

    /** Validates an integration URL and blocks private targets unless explicitly enabled. */
    public static function requireIntegrationUrl(string $url, bool $allowHttp, string $label): array
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $allowed = $allowHttp ? ['http', 'https'] : ['https'];
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, $allowed, true) || $host === '' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException($label . ' ist keine zulässige URL ohne eingebettete Zugangsdaten.');
        }
        $allowPrivate = filter_var(getenv('SWITCHLY_ALLOW_PRIVATE_INTEGRATIONS') ?: '0', FILTER_VALIDATE_BOOLEAN);
        return self::requireHost($host, $allowPrivate, $label);
    }

    /** Pins cURL to one already validated DNS answer to prevent DNS rebinding. */
    public static function curlResolve(string $url, bool $allowHttp, string $label): array
    {
        $addresses = self::requireIntegrationUrl($url, $allowHttp, $label);
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (filter_var($host, FILTER_VALIDATE_IP)) return [];
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        $address = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
        return [$host . ':' . $port . ':' . $address];
    }

    /** Resolves every address and rejects loopback, link-local and other special networks. */
    private static function requireHost(string $host, bool $allowPrivate, string $label): array
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($addresses === []) throw new RuntimeException($label . ' konnte nicht sicher aufgelöst werden.');
        foreach ($addresses as $address) {
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            $private = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false;
            if (!$public && (!$allowPrivate || !$private)) throw new RuntimeException($label . ' verweist auf eine nicht erlaubte lokale oder reservierte Adresse.');
        }
        return $addresses;
    }

    /** Returns all A and AAAA records so one unsafe answer cannot be hidden. */
    private static function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = [];
        foreach ($records as $record) {
            if (!empty($record['ip'])) $addresses[] = (string)$record['ip'];
            if (!empty($record['ipv6'])) $addresses[] = (string)$record['ipv6'];
        }
        if ($addresses === []) {
            $fallback = gethostbynamel($host) ?: [];
            foreach ($fallback as $address) $addresses[] = (string)$address;
        }
        return array_values(array_unique($addresses));
    }
}
