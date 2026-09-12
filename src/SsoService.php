<?php

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AppInfo.php';

/**
 * Implements the single configurable OpenID Connect provider.
 *
 * The authorization-code flow uses state, nonce and PKCE. ID tokens are
 * verified against the provider's JWKS before an account is resolved.
 */
final class SsoService
{
    /** @return list<array{id: string, name: string}> Providers ready for login. */
    public static function availableProviders(): array
    {
        $provider = self::providerDefinitions()['openid'];
        if (empty($provider['enabled']) || trim((string)$provider['client_id']) === '' || trim((string)$provider['discovery_url']) === '') return [];
        if (trim((string)$provider['client_secret']) === '' && empty($provider['public_client'])) return [];
        return [['id' => 'openid', 'name' => $provider['name']]];
    }

    /** @return array<string, mixed> Redacted settings suitable for the UI. */
    public static function adminSettings(): array
    {
        $settings = self::settings();
        $settings['client_secret_configured'] = trim((string)$settings['client_secret']) !== '';
        unset($settings['client_secret']);
        $settings['callback_url'] = self::callbackUrl();
        return $settings;
    }

    /**
     * Validates and persists the single OpenID Connect provider.
     *
     * @param array<string, mixed> $values
     * @throws RuntimeException For unsafe URLs or inconsistent client settings.
     */
    public static function saveSettings(array $values): void
    {
        $current = self::settings();
        $allowHttp = !empty($values['allow_http']);
        $publicClient = !empty($values['public_client']);
        $authMethod = strtolower(trim((string)($values['client_auth_method'] ?? 'post')));
        if (!in_array($authMethod, ['post', 'basic', 'none'], true)) throw new RuntimeException('Ungültige Client-Authentifizierung.');
        if ($authMethod === 'none' && !$publicClient) throw new RuntimeException('Die Authentifizierung „none“ erfordert einen öffentlichen Client.');

        $discoveryUrl = trim((string)($values['discovery_url'] ?? ''));
        $publicBaseUrl = rtrim(trim((string)($values['public_base_url'] ?? '')), '/');
        $network = ['allow_http' => $allowHttp, 'tls_verify' => !empty($values['tls_verify'])];
        if ($discoveryUrl !== '') self::requireProviderUrl($discoveryUrl, 'OpenID-Discovery-URL', $network);
        if ($publicBaseUrl !== '') self::requireProviderUrl($publicBaseUrl, 'Öffentliche CMS-URL', $network);

        $clientId = trim((string)($values['client_id'] ?? ''));
        $secret = trim((string)($values['client_secret'] ?? ''));
        if ($secret === '') $secret = (string)$current['client_secret'];
        $enabled = !empty($values['enabled']);
        if ($enabled && ($discoveryUrl === '' || $clientId === '')) throw new RuntimeException('Zum Aktivieren werden Discovery-URL und Client-ID benötigt.');
        if ($enabled && !$publicClient && $secret === '') throw new RuntimeException('Für einen vertraulichen Client fehlt das Client-Secret.');

        $scopes = trim((string)($values['scopes'] ?? 'openid email profile'));
        $scopeList = preg_split('/\s+/', $scopes) ?: [];
        if (!in_array('openid', $scopeList, true)) throw new RuntimeException('Der Scope „openid“ ist erforderlich.');
        $claims = [];
        foreach (['email_claim' => 'email', 'username_claim' => 'preferred_username', 'name_claim' => 'name', 'email_verified_claim' => 'email_verified'] as $key => $default) {
            $claim = trim((string)($values[$key] ?? $default));
            if ($claim === '' || !preg_match('/^[a-zA-Z0-9_.:-]+$/', $claim)) throw new RuntimeException('Ungültiger Claim-Name: ' . $key);
            $claims[$key] = $claim;
        }
        $domains = array_values(array_unique(array_filter(array_map(static fn(string $item): string => ltrim(strtolower(trim($item)), '@'), preg_split('/[,;\s]+/', (string)($values['allowed_domains'] ?? '')) ?: []))));
        foreach ($domains as $domain) if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) throw new RuntimeException('Ungültige erlaubte Domain: ' . $domain);

        $stmt = Database::getConnection()->prepare("UPDATE oidc_settings SET enabled=:enabled,provider_name=:name,public_base_url=:base,discovery_url=:discovery,client_id=:client_id,client_secret=:secret,scopes=:scopes,client_auth_method=:auth,public_client=:public,allow_http=:http,tls_verify=:tls,email_claim=:email_claim,username_claim=:username_claim,name_claim=:name_claim,email_verified_claim=:verified_claim,auto_create=:auto_create,allowed_domains=:domains,trust_provider_email=:trust,updated_at=CURRENT_TIMESTAMP WHERE id=1");
        $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'name' => substr(trim((string)($values['provider_name'] ?? 'OpenID Connect')) ?: 'OpenID Connect', 0, 80),
            'base' => $publicBaseUrl,
            'discovery' => $discoveryUrl,
            'client_id' => substr($clientId, 0, 500),
            'secret' => $secret,
            'scopes' => substr($scopes, 0, 500),
            'auth' => $authMethod,
            'public' => $publicClient ? 1 : 0,
            'http' => $allowHttp ? 1 : 0,
            'tls' => !empty($values['tls_verify']) ? 1 : 0,
            'email_claim' => $claims['email_claim'],
            'username_claim' => $claims['username_claim'],
            'name_claim' => $claims['name_claim'],
            'verified_claim' => $claims['email_verified_claim'],
            'auto_create' => !empty($values['auto_create']) ? 1 : 0,
            'domains' => implode(', ', $domains),
            'trust' => !empty($values['trust_provider_email']) ? 1 : 0,
        ]);
    }

    /** @return array<string, mixed> Validated discovery metadata summary. */
    public static function testConnection(): array
    {
        $provider = self::provider('openid', false);
        return [
            'issuer' => $provider['issuer'],
            'authorization_endpoint' => $provider['authorization_endpoint'],
            'token_endpoint' => $provider['token_endpoint'],
        ];
    }

    /** Builds authorization state and redirects to the provider. */
    /**
     * Creates a ten-minute state/nonce/PKCE flow and redirects to the provider.
     *
     * @throws RuntimeException When provider configuration is incomplete.
     */
    public static function start(string $providerId): never
    {
        Auth::startSession();
        $provider = self::provider($providerId);
        $state = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(64));
        $nonce = self::base64Url(random_bytes(32));

        $flows = is_array($_SESSION['sso_flows'] ?? null) ? $_SESSION['sso_flows'] : [];
        foreach ($flows as $key => $flow) {
            if (!is_array($flow) || (int)($flow['created_at'] ?? 0) < time() - 600) unset($flows[$key]);
        }
        $flows[$state] = ['provider' => $providerId, 'verifier' => $verifier, 'nonce' => $nonce, 'created_at' => time()];
        $_SESSION['sso_flows'] = $flows;

        $params = [
            'response_type' => 'code',
            'client_id' => $provider['client_id'],
            'redirect_uri' => self::callbackUrl(),
            'scope' => $provider['scope'],
            'state' => $state,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];
        $params['nonce'] = $nonce;
        header('Cache-Control: no-store');
        header('Location: ' . $provider['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    /** @return 'success'|'2fa' Result of the completed OIDC login. */
    /**
     * Validates the callback, tokens and claims, then authenticates a local user.
     *
     * @return 'success'|'2fa'
     * @throws RuntimeException For invalid state, provider errors or token checks.
     */
    public static function callback(): string
    {
        Auth::startSession();
        $state = (string)($_GET['state'] ?? '');
        $flows = is_array($_SESSION['sso_flows'] ?? null) ? $_SESSION['sso_flows'] : [];
        $flow = $state !== '' && isset($flows[$state]) && is_array($flows[$state]) ? $flows[$state] : null;
        if ($flow === null || (int)($flow['created_at'] ?? 0) < time() - 600) {
            throw new RuntimeException('Die SSO-Anfrage ist ungültig oder abgelaufen. Bitte erneut anmelden.');
        }
        unset($flows[$state]);
        $_SESSION['sso_flows'] = $flows;

        $providerId = (string)($flow['provider'] ?? '');
        $provider = self::provider($providerId);
        if (!empty($_GET['error'])) {
            $description = self::safeError((string)($_GET['error_description'] ?? $_GET['error']));
            throw new RuntimeException('SSO-Anmeldung wurde abgebrochen' . ($description !== '' ? ': ' . $description : '.'));
        }
        $code = (string)($_GET['code'] ?? '');
        if ($code === '') throw new RuntimeException('Der SSO-Anbieter hat keinen Autorisierungscode geliefert.');

        $tokenFields = [
            'grant_type' => 'authorization_code',
            'client_id' => $provider['client_id'],
            'code' => $code,
            'redirect_uri' => self::callbackUrl(),
            'code_verifier' => (string)$flow['verifier'],
        ];
        $tokenHeaders = ['Accept: application/json'];
        if (($provider['client_auth_method'] ?? 'post') === 'basic') {
            if ($provider['client_secret'] === '') throw new RuntimeException('Für client_secret_basic fehlt das OpenID-Client-Secret.');
            $tokenHeaders[] = 'Authorization: Basic ' . base64_encode($provider['client_id'] . ':' . $provider['client_secret']);
        } elseif (($provider['client_auth_method'] ?? 'post') === 'post') {
            if ($provider['client_secret'] === '') throw new RuntimeException('Für client_secret_post fehlt das OpenID-Client-Secret.');
            $tokenFields['client_secret'] = $provider['client_secret'];
        }
        $token = self::requestJson($provider['token_endpoint'], 'POST', $tokenFields, $tokenHeaders, self::networkOptions($provider));
        $accessToken = trim((string)($token['access_token'] ?? ''));
        if ($accessToken === '') throw new RuntimeException('Der SSO-Anbieter hat kein Zugriffstoken geliefert.');

        $idToken = trim((string)($token['id_token'] ?? ''));
        if ($idToken === '') throw new RuntimeException('Der OpenID-Anbieter hat kein ID-Token geliefert.');
        $idClaims = self::validateIdToken($idToken, $provider, (string)$flow['nonce']);

        $identity = self::fetchIdentity($providerId, $provider, $accessToken, $idClaims);
        self::enforceAllowedDomain((string)($identity['email'] ?? ''));
        $userId = self::resolveUser($providerId, $identity);
        return Auth::loginSsoUser($userId, (string)$provider['name']);
    }

    /** Returns the exact redirect URI that must be registered at the provider. */
    public static function callbackUrl(): string
    {
        $configured = rtrim(trim((string)self::settings()['public_base_url']), '/');
        return ($configured !== '' ? $configured : EmailService::baseUrl()) . '/sso.php';
    }

    /** Überführt die gespeicherten OIDC-Werte in die interne Anbieterdefinition. */
    private static function providerDefinitions(): array
    {
        $settings = self::settings();
        return [
            'openid' => [
                'enabled' => (bool)$settings['enabled'],
                'name' => trim((string)$settings['provider_name']) ?: 'OpenID Connect',
                'type' => 'oidc',
                'client_id' => (string)$settings['client_id'],
                'client_secret' => (string)$settings['client_secret'],
                'discovery_url' => trim((string)$settings['discovery_url']),
                'scope' => trim((string)$settings['scopes']) ?: 'openid email profile',
                'client_auth_method' => (string)$settings['client_auth_method'],
                'public_client' => (bool)$settings['public_client'],
                'allow_http' => (bool)$settings['allow_http'],
                'tls_verify' => (bool)$settings['tls_verify'],
                'email_claim' => (string)$settings['email_claim'],
                'username_claim' => (string)$settings['username_claim'],
                'name_claim' => (string)$settings['name_claim'],
                'email_verified_claim' => (string)$settings['email_verified_claim'],
            ],
        ];
    }

    /** Lädt die zentrale OpenID-Connect-Konfiguration aus der Datenbank. */
    private static function settings(): array
    {
        $settings = Database::getConnection()->query('SELECT * FROM oidc_settings WHERE id=1')->fetch();
        if (!$settings) throw new RuntimeException('OpenID-Connect-Einstellungen konnten nicht geladen werden.');
        return $settings;
    }

    /** Lädt und validiert einen verwendbaren Anbieter einschließlich Discovery-Daten. */
    private static function provider(string $id, bool $requireEnabled = true): array
    {
        $definitions = self::providerDefinitions();
        if ($id !== 'openid' || !isset($definitions[$id])) throw new RuntimeException('Unbekannter OpenID-Connect-Anbieter.');
        $provider = $definitions[$id];
        if ($requireEnabled && empty($provider['enabled'])) throw new RuntimeException('OpenID Connect ist nicht aktiviert.');
        if (trim((string)$provider['client_id']) === '' || (trim((string)$provider['client_secret']) === '' && empty($provider['public_client']))) {
            throw new RuntimeException('Dieser SSO-Anbieter ist nicht vollständig konfiguriert.');
        }
        if (!empty($provider['public_client']) && trim((string)$provider['client_secret']) === '') $provider['client_auth_method'] = 'none';
        if (($provider['client_auth_method'] ?? 'post') === 'none' && empty($provider['public_client'])) throw new RuntimeException('OpenID-Client-Authentifizierung „none“ erfordert einen öffentlichen Client.');
        if (!empty($provider['discovery_url'])) {
            $network = self::networkOptions($provider);
            self::requireProviderUrl((string)$provider['discovery_url'], 'OpenID-Discovery-URL', $network);
            $metadata = self::requestJson((string)$provider['discovery_url'], 'GET', [], [], $network);
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri', 'issuer'] as $field) {
                $provider[$field] = trim((string)($metadata[$field] ?? ''));
                if ($field !== 'issuer') self::requireProviderUrl((string)$provider[$field], 'OpenID ' . $field, $network);
            }
            $supportedAuth = is_array($metadata['token_endpoint_auth_methods_supported'] ?? null) ? $metadata['token_endpoint_auth_methods_supported'] : [];
            $selectedAuth = ['post' => 'client_secret_post', 'basic' => 'client_secret_basic', 'none' => 'none'][$provider['client_auth_method'] ?? 'post'];
            if ($supportedAuth !== [] && !in_array($selectedAuth, $supportedAuth, true)) throw new RuntimeException('Der OpenID-Anbieter unterstützt die konfigurierte Client-Authentifizierung nicht: ' . $selectedAuth);
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $field) {
            if (empty($provider[$field])) throw new RuntimeException('Beim SSO-Anbieter fehlt ' . $field . '.');
            self::requireProviderUrl((string)$provider[$field], 'SSO-Endpunkt', self::networkOptions($provider));
        }
        return $provider;
    }

    /** Verknüpft UserInfo und ID-Token zu einer normalisierten, geprüften Identität. */
    private static function fetchIdentity(string $providerId, array $provider, string $accessToken, array $idClaims = []): array
    {
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];
        $profile = self::requestJson((string)$provider['userinfo_endpoint'], 'GET', [], $headers, self::networkOptions($provider));
        $profileSubject = (string)($profile['sub'] ?? '');
        $tokenSubject = (string)($idClaims['sub'] ?? '');
        if ($profileSubject === '' || $tokenSubject === '' || !hash_equals($tokenSubject, $profileSubject)) {
            throw new RuntimeException('OpenID-ID-Token und UserInfo gehören nicht zum selben Benutzer.');
        }
        $emailClaim = (string)($provider['email_claim'] ?? 'email');
        $usernameClaim = (string)($provider['username_claim'] ?? 'preferred_username');
        $nameClaim = (string)($provider['name_claim'] ?? 'name');
        $verifiedClaim = (string)($provider['email_verified_claim'] ?? 'email_verified');
        $emailValue = trim((string)self::claimValue($profile, $idClaims, $emailClaim));
        $email = strtolower($emailValue);
        $verified = filter_var(self::claimValue($profile, $idClaims, $verifiedClaim), FILTER_VALIDATE_BOOLEAN);
        return [
            'subject' => $profileSubject,
            'email' => $email,
            'email_verified' => $verified,
            'display_name' => (string)(self::claimValue($profile, $idClaims, $nameClaim) ?: self::claimValue($profile, $idClaims, $usernameClaim) ?: $email),
            'login' => (string)(self::claimValue($profile, $idClaims, $usernameClaim) ?: $email),
        ];
    }

    /** Liest einen einfachen oder punktgetrennten Claim zuerst aus UserInfo und dann aus dem ID-Token. */
    private static function claimValue(array $profile, array $claims, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') return null;
        foreach ([$profile, $claims] as $source) {
            if (array_key_exists($path, $source) && $source[$path] !== '' && $source[$path] !== null) return $source[$path];
            $value = $source;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) { $value = null; break; }
                $value = $value[$segment];
            }
            if ($value !== '' && $value !== null) return $value;
        }
        return null;
    }

    /** Prüft JWT-Aufbau, RSA-Signatur, Zeitangaben, Nonce, Zielgruppe und Aussteller. */
    private static function validateIdToken(string $jwt, array $provider, string $expectedNonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new RuntimeException('Das OpenID-ID-Token hat kein gültiges JWT-Format.');
        $headerJson = self::base64UrlDecode($parts[0]);
        $claimsJson = self::base64UrlDecode($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);
        $header = $headerJson !== false ? json_decode($headerJson, true) : null;
        $claims = $claimsJson !== false ? json_decode($claimsJson, true) : null;
        if (!is_array($header) || !is_array($claims) || $signature === false) throw new RuntimeException('Das OpenID-ID-Token konnte nicht gelesen werden.');
        $algorithm = (string)($header['alg'] ?? '');
        $opensslAlgorithms = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
        if (!isset($opensslAlgorithms[$algorithm]) || trim((string)($header['kid'] ?? '')) === '') throw new RuntimeException('Der OpenID-Anbieter verwendet keinen unterstützten RSA-Signaturschlüssel (RS256/RS384/RS512).');

        $jwksUrl = trim((string)($provider['jwks_uri'] ?? ''));
        $network = self::networkOptions($provider);
        self::requireProviderUrl($jwksUrl, 'OpenID-JWKS-URL', $network);
        $jwks = self::requestJson($jwksUrl, 'GET', [], [], $network);
        $jwk = null;
        foreach (($jwks['keys'] ?? []) as $candidate) {
            if (!is_array($candidate) || (string)($candidate['kid'] ?? '') !== (string)$header['kid']) continue;
            if (($candidate['kty'] ?? '') !== 'RSA' || (!empty($candidate['alg']) && $candidate['alg'] !== $algorithm)) continue;
            $jwk = $candidate;
            break;
        }
        if ($jwk === null) throw new RuntimeException('Der Signaturschlüssel des OpenID-ID-Tokens wurde nicht gefunden.');
        if (!function_exists('openssl_verify')) throw new RuntimeException('Die OpenSSL-Erweiterung für die OpenID-Signaturprüfung fehlt.');
        $pem = self::rsaJwkToPem($jwk);
        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, $opensslAlgorithms[$algorithm]) !== 1) {
            throw new RuntimeException('Die Signatur des OpenID-ID-Tokens ist ungültig.');
        }

        $now = time();
        if ((int)($claims['exp'] ?? 0) < $now - 30) throw new RuntimeException('Das OpenID-ID-Token ist abgelaufen.');
        if ((int)($claims['iat'] ?? 0) > $now + 300) throw new RuntimeException('Das OpenID-ID-Token wurde mit einer ungültigen Zeit ausgestellt.');
        if (isset($claims['nbf']) && (int)$claims['nbf'] > $now + 300) throw new RuntimeException('Das OpenID-ID-Token ist noch nicht gültig.');
        $nonce = (string)($claims['nonce'] ?? '');
        if ($nonce === '' || !hash_equals($expectedNonce, $nonce)) throw new RuntimeException('Die Nonce des OpenID-ID-Tokens ist ungültig.');
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [(string)($claims['aud'] ?? '')];
        if (!in_array((string)$provider['client_id'], $audiences, true)) throw new RuntimeException('Das OpenID-ID-Token wurde nicht für diese Anwendung ausgestellt.');
        if (count($audiences) > 1 && (string)($claims['azp'] ?? '') !== (string)$provider['client_id']) {
            throw new RuntimeException('Der autorisierte Empfänger des OpenID-ID-Tokens ist ungültig.');
        }
        $expectedIssuer = trim((string)($provider['issuer'] ?? ''));
        if (str_contains($expectedIssuer, '{tenantid}')) {
            $tenantId = trim((string)($claims['tid'] ?? ''));
            $expectedIssuer = str_replace('{tenantid}', $tenantId, $expectedIssuer);
        }
        if ($expectedIssuer === '' || !hash_equals(rtrim($expectedIssuer, '/'), rtrim((string)($claims['iss'] ?? ''), '/'))) {
            throw new RuntimeException('Der Aussteller des OpenID-ID-Tokens ist ungültig.');
        }
        if (trim((string)($claims['sub'] ?? '')) === '') throw new RuntimeException('Im OpenID-ID-Token fehlt die Benutzer-ID.');
        return $claims;
    }

    /** Konvertiert einen RSA-Schlüssel aus dem JWK-Format in einen PEM Public Key. */
    private static function rsaJwkToPem(array $jwk): string
    {
        $modulus = self::base64UrlDecode((string)($jwk['n'] ?? ''));
        $exponent = self::base64UrlDecode((string)($jwk['e'] ?? ''));
        if ($modulus === false || $modulus === '' || $exponent === false || $exponent === '') {
            throw new RuntimeException('Der OpenID-RSA-Schlüssel ist unvollständig.');
        }
        $rsaKey = self::asn1Sequence(self::asn1Integer($modulus) . self::asn1Integer($exponent));
        $rsaAlgorithmIdentifier = hex2bin('300d06092a864886f70d0101010500');
        if ($rsaAlgorithmIdentifier === false) throw new RuntimeException('RSA-Schlüssel konnte nicht vorbereitet werden.');
        $bitString = "\x03" . self::asn1Length(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $publicKey = self::asn1Sequence($rsaAlgorithmIdentifier . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKey), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Kodiert einen vorzeichenlosen Binärwert als ASN.1-INTEGER. */
    private static function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') $value = "\x00";
        if ((ord($value[0]) & 0x80) !== 0) $value = "\x00" . $value;
        return "\x02" . self::asn1Length(strlen($value)) . $value;
    }

    /** Umschließt Binärdaten mit einer ASN.1-SEQUENCE. */
    private static function asn1Sequence(string $value): string
    {
        return "\x30" . self::asn1Length(strlen($value)) . $value;
    }

    /** Kodiert eine Länge nach den ASN.1-DER-Regeln. */
    private static function asn1Length(int $length): string
    {
        if ($length < 128) return chr($length);
        $encoded = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    /** Findet, verknüpft oder erstellt das lokale Konto für eine geprüfte SSO-Identität. */
    private static function resolveUser(string $provider, array $identity): int
    {
        $settings = self::settings();
        $subject = trim((string)($identity['subject'] ?? ''));
        $email = strtolower(trim((string)($identity['email'] ?? '')));
        $emailVerified = !empty($identity['email_verified']);
        if ($subject === '' || strlen($subject) > 255) throw new RuntimeException('Der SSO-Anbieter hat keine gültige Benutzer-ID geliefert.');

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT u.id FROM sso_identities i JOIN users u ON u.id=i.user_id WHERE i.provider=:provider AND i.subject=:subject');
        $stmt->execute(['provider' => $provider, 'subject' => $subject]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            $db->prepare('UPDATE sso_identities SET email=:email,display_name=:name,last_login_at=CURRENT_TIMESTAMP WHERE provider=:provider AND subject=:subject')->execute([
                'email' => $email !== '' ? $email : null,
                'name' => substr((string)($identity['display_name'] ?? ''), 0, 200),
                'provider' => $provider,
                'subject' => $subject,
            ]);
            return (int)$existingId;
        }

        $user = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailStmt = $db->prepare('SELECT * FROM users WHERE lower(email)=lower(:email) ORDER BY id');
            $emailStmt->execute(['email' => $email]);
            $matches = $emailStmt->fetchAll();
            if (count($matches) > 1) throw new RuntimeException('Die SSO-E-Mail ist mehreren lokalen Konten zugeordnet.');
            $user = $matches[0] ?? null;
        }
        if ($user !== null && !$emailVerified && empty($settings['trust_provider_email'])) {
            throw new RuntimeException('Der SSO-Anbieter hat die E-Mail-Adresse nicht als bestätigt ausgewiesen.');
        }
        if ($user === null) {
            if (empty($settings['auto_create'])) {
                throw new RuntimeException('Für diese OpenID-E-Mail existiert kein lokales Konto. Ein Administrator muss zuerst einen Benutzer mit derselben E-Mail anlegen oder die automatische Benutzeranlage aktivieren.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Für die automatische Benutzeranlage muss der SSO-Anbieter eine gültige E-Mail liefern.');
            if (!$emailVerified && empty($settings['trust_provider_email'])) throw new RuntimeException('Für die automatische Benutzeranlage muss die OpenID-E-Mail bestätigt sein.');
            $username = self::uniqueUsername((string)($identity['login'] ?? ''), $email);
            $insert = $db->prepare("INSERT INTO users (username,password_hash,role,email,email_verified_at) VALUES (:username,:hash,'user',:email,CURRENT_TIMESTAMP)");
            $insert->execute(['username' => $username, 'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'email' => $email]);
            $user = ['id' => (int)$db->lastInsertId(), 'username' => $username];
            // Ein automatisch angelegtes Konto erhält nicht die historischen
            // Standard-Leserechte. Ein Administrator weist Rechte bewusst zu.
            $db->prepare("INSERT INTO user_permissions (user_id,permission_code,allowed) VALUES (:id,'dashboard.view',0)")->execute(['id' => $user['id']]);
            Logger::info('SSO_USER_CREATED', 'Benutzer automatisch über ' . $provider . ' angelegt.', $username);
        } elseif ($emailVerified && empty($user['email_verified_at'])) {
            $db->prepare('UPDATE users SET email_verified_at=CURRENT_TIMESTAMP,email_verification_token=NULL,email_verification_expires_at=NULL WHERE id=:id')->execute(['id' => $user['id']]);
        }

        $insertIdentity = $db->prepare('INSERT INTO sso_identities (provider,subject,user_id,email,display_name) VALUES (:provider,:subject,:user_id,:email,:name)');
        $insertIdentity->execute([
            'provider' => $provider,
            'subject' => $subject,
            'user_id' => $user['id'],
            'email' => $email !== '' ? $email : null,
            'name' => substr((string)($identity['display_name'] ?? ''), 0, 200),
        ]);
        Logger::info('SSO_IDENTITY_LINKED', 'SSO-Identität ' . $provider . ' mit Benutzer verknüpft.', (string)$user['username']);
        return (int)$user['id'];
    }

    /** Erzeugt einen zulässigen und noch nicht vergebenen lokalen Benutzernamen. */
    private static function uniqueUsername(string $preferred, string $email): string
    {
        $candidate = trim($preferred) !== '' ? trim($preferred) : (string)strstr($email, '@', true);
        $candidate = strtolower((string)preg_replace('/[^a-zA-Z0-9._-]+/', '_', $candidate));
        $candidate = trim(substr($candidate, 0, 40), '._-');
        if ($candidate === '') $candidate = 'sso_user';
        $db = Database::getConnection();
        $base = $candidate;
        for ($suffix = 0; $suffix < 10000; $suffix++) {
            $candidate = $suffix === 0 ? $base : substr($base, 0, 35) . '_' . $suffix;
            $stmt = $db->prepare('SELECT 1 FROM users WHERE lower(username)=lower(:username)');
            $stmt->execute(['username' => $candidate]);
            if ($stmt->fetchColumn() === false) return $candidate;
        }
        throw new RuntimeException('Es konnte kein eindeutiger Benutzername für das SSO-Konto erzeugt werden.');
    }

    /** Beschränkt SSO-Anmeldungen auf die konfigurierten E-Mail-Domains. */
    private static function enforceAllowedDomain(string $email): void
    {
        $raw = trim((string)self::settings()['allowed_domains']);
        if ($raw === '') return;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Der SSO-Anbieter hat keine E-Mail für die Domain-Prüfung geliefert.');
        $allowed = array_values(array_filter(array_map(static fn(string $item): string => ltrim(strtolower(trim($item)), '@'), preg_split('/[,;\s]+/', $raw) ?: [])));
        $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
        if (!in_array($domain, $allowed, true)) throw new RuntimeException('Diese E-Mail-Domain ist für SSO nicht freigegeben.');
    }

    /** Führt eine abgesicherte HTTP-Anfrage aus und erwartet eine erfolgreiche JSON-Antwort. */
    private static function requestJson(string $url, string $method = 'GET', array $fields = [], array $headers = [], array $network = []): array
    {
        self::requireProviderUrl($url, 'SSO-URL', $network);
        $headers[] = 'User-Agent: ' . AppInfo::USER_AGENT;
        if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        }
        if (array_key_exists('tls_verify', $network) && empty($network['tls_verify'])) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if (defined('CURLOPT_PROTOCOLS')) $options[CURLOPT_PROTOCOLS] = !empty($network['allow_http']) ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS;
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) throw new RuntimeException('SSO-Verbindung fehlgeschlagen: ' . ($error !== '' ? $error : 'unbekannter Netzwerkfehler'));
        $data = json_decode((string)$response, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($data) ? self::safeError((string)($data['error_description'] ?? $data['message'] ?? $data['error'] ?? '')) : '';
            throw new RuntimeException('SSO-Anbieter antwortete mit HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.'));
        }
        if (!is_array($data)) throw new RuntimeException('Der SSO-Anbieter hat keine gültige JSON-Antwort geliefert.');
        return $data;
    }

    /** Validiert externe Anbieter-URLs und verbietet eingebettete Zugangsdaten. */
    private static function requireProviderUrl(string $url, string $label, array $network = []): void
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $allowedSchemes = !empty($network['allow_http']) ? ['http', 'https'] : ['https'];
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, $allowedSchemes, true) || (string)parse_url($url, PHP_URL_HOST) === '' || (string)parse_url($url, PHP_URL_USER) !== '' || (string)parse_url($url, PHP_URL_PASS) !== '') {
            throw new RuntimeException($label . ' muss eine gültige ' . (!empty($network['allow_http']) ? 'HTTP-/HTTPS-' : 'HTTPS-') . 'Adresse ohne eingebettete Zugangsdaten sein.');
        }
    }

    /** Leitet die zulässigen Transportoptionen aus der Anbieterkonfiguration ab. */
    private static function networkOptions(array $provider): array
    {
        return [
            'allow_http' => !empty($provider['allow_http']),
            'tls_verify' => !array_key_exists('tls_verify', $provider) || !empty($provider['tls_verify']),
        ];
    }

    /** Kodiert Binärdaten ohne Padding im Base64URL-Format. */
    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** Dekodiert einen Base64URL-Wert streng und ergänzt nur erforderliches Padding. */
    private static function base64UrlDecode(string $value): string|false
    {
        $padding = (4 - strlen($value) % 4) % 4;
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    }

    /** Kürzt und bereinigt Fehltexte eines externen Anbieters für die Anzeige. */
    private static function safeError(string $value): string
    {
        return trim(substr((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags($value)), 0, 300));
    }
}
