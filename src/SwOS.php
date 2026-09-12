<?php

declare(strict_types=1);

/**
 * Minimal HTTP-Digest client for the JavaScript-like SwOS management API.
 *
 * SwOS field names and encodings vary between device families and firmware
 * releases. Parsing therefore preserves unknown values where possible and
 * writing fails explicitly when a required field is absent.
 */
final class SwOS
{
    /**
     * @param array{host: string, port?: int, username?: string, password?: string, scheme?: string} $config
     */
    public function __construct(private array $config) {}

    /**
     * Executes one authenticated device request.
     *
     * $allowDisconnect is reserved for actions such as reboot where the
     * device may close the connection before returning an HTTP response.
     */
    private function request(string $endpoint, string $method = 'GET', mixed $body = null, array $headers = [], int $timeout = 8, bool $allowDisconnect = false): string
    {
        $url = sprintf(
            '%s://%s:%d%s',
            $this->config['scheme'] ?? 'http',
            $this->config['host'],
            $this->config['port'] ?? 80,
            $endpoint
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
            CURLOPT_USERPWD => ($this->config['username'] ?? 'admin') . ':' . ($this->config['password'] ?? ''),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TCP_NODELAY => 1,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        if ($method !== 'GET') {
            if ($method === 'POST' && is_array($body)) {
                // Multipart uploads must use CURLOPT_POST. Some SwOS releases
                // reject a multipart body sent with a custom request method.
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
        }
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($headers !== []) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            if ($allowDisconnect && in_array($curlErrno, [CURLE_GOT_NOTHING, CURLE_RECV_ERROR], true)) return '';
            throw new RuntimeException($curlError !== '' ? $curlError : 'SwOS nicht erreichbar.');
        }
        if (($httpCode < 200 || $httpCode >= ($allowDisconnect ? 400 : 300)) && !($allowDisconnect && $httpCode === 0)) {
            throw new RuntimeException("SwOS antwortet mit HTTP {$httpCode}.");
        }

        return (string)$response;
    }

    /** Converts SwOS object syntax, quoted strings and hexadecimal values. */
    private function parsePayload(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $text = preg_replace('/([a-zA-Z0-9_]+):/', '"$1":', $text);
        $text = str_replace("'", '"', $text);
        $text = preg_replace_callback('/0x([0-9a-fA-F]+)/', static fn(array $m): string => (string)hexdec($m[1]), $text);
        $data = json_decode($text, true);

        return is_array($data) ? $data : [];
    }

    /** Wandelt dezimale und von SwOS verwendete hexadezimale Werte in Ganzzahlen um. */
    private function parseNumber(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (is_float($value)) return (int)$value;
        if (is_string($value)) {
            $value = trim($value);
            if (str_starts_with($value, '0x')) return (int)hexdec($value);
            if (is_numeric($value)) return (int)$value;
        }
        return 0;
    }

    /** Liest einen Portwert über mehrere firmwareabhängige Feldnamen. */
    private function indexedValue(array $data, array $keys, int $index, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) continue;
            $value = $data[$key];
            if (is_array($value) && array_key_exists($index, $value)) return $value[$index];
        }
        return $default;
    }

    /** Ermittelt einen booleschen Portzustand aus Bitmaske oder Arraydarstellung. */
    private function maskEnabled(array $data, array $keys, int $index, bool $default = false): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) continue;
            $value = $data[$key];
            if (is_array($value)) return !empty($value[$index]);
            return (($this->parseNumber($value) & (1 << $index)) !== 0);
        }
        return $default;
    }

    /** Dekodiert den kompakten Linkstatus in Geschwindigkeit und Duplexmodus. */
    private function decodeLinkState(mixed $raw, bool $up): array
    {
        if (!$up) return ['speed' => 'Down', 'duplex' => '—'];
        $code = $this->parseNumber($raw);
        // SwOS exposes the negotiated state as a compact numeric code on many
        // CRS/CSS releases. Unknown codes remain visible instead of pretending
        // every active link is 1 Gbit/s.
        $states = [
            1 => ['10M', 'Half'], 2 => ['10M', 'Full'],
            3 => ['100M', 'Half'], 4 => ['100M', 'Full'],
            5 => ['1G', 'Half'], 6 => ['1G', 'Full'],
            7 => ['10G', 'Full'], 8 => ['40G', 'Full'],
            9 => ['2.5G', 'Full'], 10 => ['5G', 'Full'],
            11 => ['25G', 'Full'], 12 => ['100G', 'Full'],
        ];
        if (isset($states[$code])) return ['speed' => $states[$code][0], 'duplex' => $states[$code][1]];
        if ($code >= 10 && $code <= 100000) {
            $label = $code >= 1000 ? rtrim(rtrim(number_format($code / 1000, 2, '.', ''), '0'), '.') . 'G' : $code . 'M';
            return ['speed' => $label, 'duplex' => 'Full'];
        }
        return ['speed' => 'Link aktiv', 'duplex' => '—'];
    }

    /** Ordnet einen konfigurierten SwOS-Geschwindigkeitscode einer lesbaren Bezeichnung zu. */
    private function decodeSpeedCode(mixed $raw, bool $up): string
    {
        if (!$up) return 'Down';
        $code = $this->parseNumber($raw);
        // CRS3xx/CSS3xx SwOS 2.x: spd = Ist-Wert, spdc = Soll-Wert.
        $speeds = [
            0 => '10M', 1 => '100M', 2 => '1G', 3 => '10G',
            4 => '2.5G', 5 => '5G', 6 => '25G',
        ];
        return $speeds[$code] ?? ('Speed-Code ' . $code);
    }

    /** Serialisiert skalare und verschachtelte Werte in die von SwOS erwartete Syntax. */
    private function serializePayload(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) return '[' . implode(',', array_map(fn(mixed $item): string => $this->serializePayload($item), $value)) . ']';
            $parts = [];
            foreach ($value as $key => $item) $parts[] = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$key) . ':' . $this->serializePayload($item);
            return '{' . implode(',', $parts) . '}';
        }
        if (is_int($value)) return '0x' . dechex($value);
        if (is_float($value)) return (string)$value;
        if (is_bool($value)) return $value ? '0x01' : '0x00';
        if ($value === null) return '0x00';
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value) . "'";
    }

    /**
     * SwOS accepts JavaScript-like data, but the VLAN table is stricter than
     * the other endpoints about hexadecimal field widths. In particular VID
     * must be sent as 0x0001..0x0ffe; a shortened value such as 0xa is parsed
     * as zero by CRS3xx/SwOS 2.18.
     */
    private function serializeVlanTable(array $rows): string
    {
        $serializedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $fields = [];
            foreach ($row as $key => $value) {
                $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$key);
                if ($safeKey === '') continue;
                if ($safeKey === 'nm') {
                    $encoded = $this->serializePayload((string)$value);
                } elseif ($safeKey === 'mbr') {
                    $encoded = '0x' . str_pad(dechex($this->parseNumber($value) & 0xffffffff), 8, '0', STR_PAD_LEFT);
                } elseif ($safeKey === 'vid') {
                    $encoded = '0x' . str_pad(dechex($this->parseNumber($value) & 0xffff), 4, '0', STR_PAD_LEFT);
                } elseif (in_array($safeKey, ['piso', 'lrn', 'mrr', 'igmp'], true)) {
                    $encoded = '0x' . str_pad(dechex($this->parseNumber($value) & 0xff), 2, '0', STR_PAD_LEFT);
                } else {
                    $encoded = $this->serializePayload($value);
                }
                $fields[] = $safeKey . ':' . $encoded;
            }
            $serializedRows[] = '{' . implode(',', $fields) . '}';
        }
        return '[' . implode(',', $serializedRows) . ']';
    }

    /** Sucht ein Arrayfeld im Rohtext und merkt seine genaue Zeichenposition. */
    private function arrayField(string $raw, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (!preg_match('/(\\b' . preg_quote($key, '/') . '\\s*:\\s*\\[)(.*?)(\\])/s', $raw, $m, PREG_OFFSET_CAPTURE)) continue;
            $tokens = trim($m[2][0]) === '' ? [] : preg_split('/\\s*,\\s*/', trim($m[2][0]));
            return ['key' => $key, 'tokens' => is_array($tokens) ? $tokens : [], 'offset' => $m[2][1], 'length' => strlen($m[2][0])];
        }
        return null;
    }

    /** Ersetzt ein gefundenes Arrayfeld, ohne unbekannte Nachbarfelder zu verändern. */
    private function replaceArrayField(string $raw, array $field, array $tokens): string
    {
        return substr($raw, 0, $field['offset']) . implode(',', $tokens) . substr($raw, $field['offset'] + $field['length']);
    }

    /** Ändert einen einzelnen Index innerhalb eines SwOS-Arrayfelds. */
    private function updateArrayValue(string $raw, array $keys, int $index, string $token): string
    {
        $field = $this->arrayField($raw, $keys);
        if ($field === null || !array_key_exists($index, $field['tokens'])) {
            throw new RuntimeException('SwOS-Feld ' . implode('/', $keys) . ' wurde nicht gefunden. Diese Firmware wird für diese Änderung nicht unterstützt.');
        }
        $field['tokens'][$index] = $token;
        return $this->replaceArrayField($raw, $field, $field['tokens']);
    }

    /** Ändert einen Portzustand in Bitmaske oder Arraydarstellung. */
    private function updateMaskValue(string $raw, array $keys, int $index, bool $enabled): string
    {
        foreach ($keys as $key) {
            if (!preg_match('/\\b' . preg_quote($key, '/') . '\\s*:\\s*(0x[0-9a-fA-F]+|\\d+)/', $raw, $m, PREG_OFFSET_CAPTURE)) continue;
            $mask = $this->parseNumber($m[1][0]);
            $bit = 1 << $index;
            $mask = $enabled ? ($mask | $bit) : ($mask & ~$bit);
            $replacement = str_starts_with(strtolower($m[1][0]), '0x')
                ? '0x' . str_pad(dechex($mask & 0xffffffff), 8, '0', STR_PAD_LEFT)
                : (string)$mask;
            return substr($raw, 0, $m[1][1]) . $replacement . substr($raw, $m[1][1] + strlen($m[1][0]));
        }
        // A few SwOS Lite builds use an array instead of a bit mask.
        return $this->updateArrayValue($raw, $keys, $index, $enabled ? '1' : '0');
    }

    /** Lädt einen SwOS-Endpunkt und weist unerwartet leere Antworten zurück. */
    private function endpointPayload(string $endpoint, bool $allowEmptyList = false): array
    {
        $raw = $this->request($endpoint);
        $parsed = $this->parsePayload($raw);
        if ($parsed === [] && !($allowEmptyList && trim($raw) === '[]')) throw new RuntimeException($endpoint . ' lieferte keine auswertbaren Daten.');
        return [$raw, $parsed];
    }

    /** Dekodiert eine hexadezimale SwOS-Zeichenfolge ohne ungültige Bytes zu übernehmen. */
    private function hexToString(string $hex): string
    {
        $hex = trim($hex);
        if ($hex === '' || !ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
            return $hex;
        }

        $decoded = hex2bin($hex);
        return $decoded === false ? $hex : trim(str_replace("\0", '', $decoded));
    }

    /** Formatiert SwOS-Zeitticks als kompakte Laufzeitangabe. */
    private function uptimeToText(int $ticks): string
    {
        if ($ticks <= 0) return '0m';
        $seconds = intdiv($ticks, 100);
        if ($seconds < 60) return $seconds . 's';

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) $parts[] = $days . 'd';
        if ($hours > 0 || $days > 0) $parts[] = $hours . 'h';
        $parts[] = $minutes . 'm';
        return implode(' ', $parts);
    }

    /** @return array<string, mixed> Normalized system information. */
    public function getSystemInfo(): array
    {
        $data = $this->parsePayload($this->request('/sys.b'));
        $rawUptime = $this->parseNumber($data['upt'] ?? $data['uptime'] ?? 0);

        $voltage = null;
        if (isset($data['p1v'])) {
            $voltage = round($this->parseNumber($data['p1v']) / 1000, 1);
        } elseif (isset($data['volt'])) {
            $rawVoltage = $this->parseNumber($data['volt']);
            $voltage = $rawVoltage > 100 ? round($rawVoltage / 10, 1) : $rawVoltage;
        }

        return [
            'model' => $this->hexToString((string)($data['brd'] ?? $data['model'] ?? 'Unbekannt')),
            'identity' => $this->hexToString((string)($data['id'] ?? '')),
            'version' => $this->hexToString((string)($data['ver'] ?? '')),
            'temperature' => isset($data['temp']) ? $this->parseNumber($data['temp']) : null,
            'voltage' => $voltage,
            'uptime' => $this->uptimeToText($rawUptime),
            'mac' => (string)($data['mac'] ?? ''),
        ];
    }

    /** @return list<array<string, mixed>> Normalized port status and counters. */
    public function getPortStatistics(): array
    {
        $link = $this->parsePayload($this->request('/link.b'));
        $stats = $this->parsePayload($this->request('/stats.b'));
        $count = isset($link['nm']) && is_array($link['nm']) ? count($link['nm']) : 0;
        $ports = [];
        $enabledMask = $this->parseNumber($link['en'] ?? 0xffffffff);

        for ($i = 0; $i < $count; $i++) {
            $name = $this->hexToString((string)($link['nm'][$i] ?? ''));
            if ($name === '') $name = 'Port ' . ($i + 1);
            $isQsfpLane = str_starts_with(strtoupper($name), 'QSFP');
            $isFiber = $isQsfpLane || $this->maskEnabled($link, ['sfpr'], $i, false);
            $speedOptions = $isQsfpLane
                ? [['value' => 3, 'label' => '10 Gbit/s']]
                : ($isFiber
                    ? [['value' => 2, 'label' => '1 Gbit/s'], ['value' => 3, 'label' => '10 Gbit/s']]
                    : [['value' => 0, 'label' => '10 Mbit/s'], ['value' => 1, 'label' => '100 Mbit/s'], ['value' => 2, 'label' => '1 Gbit/s'], ['value' => 3, 'label' => '10 Gbit/s']]);

            $up = false;
            if (isset($link['lnk'])) {
                $up = is_array($link['lnk'])
                    ? !empty($link['lnk'][$i])
                    : (($this->parseNumber($link['lnk']) & (1 << $i)) !== 0);
            }
            $actualSpeedCode = $this->indexedValue($link, ['spd', 'rate', 'lnksp'], $i, null);
            $autoNegotiation = $this->maskEnabled($link, ['an', 'autoneg', 'auto'], $i, true);
            $speedCode = $this->indexedValue($link, ['spdc', 'sp', 'speedc'], $i, null);

            $ports[] = [
                'port' => $i + 1,
                'name' => $name,
                'link' => $up,
                'enabled' => (($enabledMask & (1 << $i)) !== 0),
                'speed' => $this->decodeSpeedCode($actualSpeedCode, $up),
                'duplex' => $up ? ($this->maskEnabled($link, ['dpx', 'duplex'], $i, true) ? 'Full' : 'Half') : '—',
                'auto_negotiation' => $autoNegotiation,
                'configured_speed_code' => $speedCode === null ? null : $this->parseNumber($speedCode),
                'speed_options' => $speedOptions,
                'rx_bytes' => $this->parseNumber($stats['rb'][$i] ?? 0),
                'tx_bytes' => $this->parseNumber($stats['tb'][$i] ?? 0),
                'rx_packets' => $this->parseNumber($stats['rbp'][$i] ?? 0),
                'tx_packets' => $this->parseNumber($stats['tbp'][$i] ?? 0),
                'rx_errors' => $this->parseNumber($stats['rae'][$i] ?? 0),
                'tx_errors' => $this->parseNumber($stats['tae'][$i] ?? 0),
            ];
        }

        return $ports;
    }

    /** Requests a reboot; a disconnect before the response is expected on SwOS. */
    public function reboot(): void
    {
        // Beim Reboot beendet SwOS die HTTP-Verbindung teilweise ohne Antwort.
        $this->request('/reboot', 'POST', '*', ['Content-Type: text/plain'], 10, true);
    }

    /** Returns the binary `.swb` configuration exported by the device. */
    public function downloadBackup(): string
    {
        return $this->request('/backup.swb', 'GET', null, [], 30);
    }

    /** Uploads a validated backup file; callers trigger the required reboot. */
    public function restoreBackup(string $path, string $originalName = 'backup.swb'): void
    {
        if (!is_file($path)) throw new RuntimeException('Backup-Datei wurde nicht gefunden.');
        if (!is_readable($path) || filesize($path) === 0) throw new RuntimeException('Backup-Datei ist leer oder nicht lesbar.');
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName)) ?: 'backup.swb';
        $file = new CURLFile($path, 'application/octet-stream', $safeName);
        // A successful restore can immediately reboot/close the HTTP socket.
        // CURLE_GOT_NOTHING/CURLE_RECV_ERROR are therefore accepted only here.
        $response = $this->request('/backup.swb', 'POST', ['file' => $file], ['Expect:'], 90, true);
        if (preg_match('/\\b(invalid|error|failed|corrupt)\\b/i', strip_tags($response))) {
            throw new RuntimeException('SwOS hat die Backup-Datei abgelehnt: ' . trim(strip_tags($response)));
        }
    }

    /** Updates the enabled-port bitmask in `/link.b`. */
    public function setPortEnabled(int $port, bool $enabled): void
    {
        if ($port < 1 || $port > 32) throw new RuntimeException('Portnummer außerhalb des gültigen Bereichs.');
        $link = $this->parsePayload($this->request('/link.b'));
        $mask = $this->parseNumber($link['en'] ?? 0xffffffff);
        $bit = 1 << ($port - 1);
        $mask = $enabled ? ($mask | $bit) : ($mask & ~$bit);
        $payload = '{en:0x' . str_pad(dechex($mask & 0xffffffff), 8, '0', STR_PAD_LEFT) . '}';
        $this->request('/link.b', 'POST', $payload, ['Content-Type: application/x-javascript'], 10);
    }

    /** Rewrites one hex-encoded port name while retaining unknown payload fields. */
    public function renamePort(int $port, string $name): void
    {
        if ($port < 1 || $port > 32) throw new RuntimeException('Portnummer außerhalb des gültigen Bereichs.');
        $name = trim($name);
        if ($name === '' || strlen($name) > 32) throw new RuntimeException('Der Portname muss 1 bis 32 Bytes lang sein.');
        $raw = $this->request('/link.b');
        if (!preg_match('/(\bnm\s*:\s*\[)(.*?)(\])/s', $raw, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Portnamen konnten in link.b nicht gefunden werden.');
        }
        $items = preg_split('/\s*,\s*/', $match[2][0]);
        $index = $port - 1;
        if (!is_array($items) || !array_key_exists($index, $items)) throw new RuntimeException('Port wurde in link.b nicht gefunden.');
        $old = trim((string)$items[$index]);
        $quote = (str_starts_with($old, "'") || str_starts_with($old, '"')) ? $old[0] : '';
        $items[$index] = $quote . bin2hex($name) . $quote;
        $newArray = implode(',', $items);
        $offset = $match[2][1];
        $payload = substr($raw, 0, $offset) . $newArray . substr($raw, $offset + strlen($match[2][0]));
        $this->request('/link.b', 'POST', $payload, ['Content-Type: text/plain'], 15);
    }

    /** Changes auto-negotiation and, when disabled, the configured speed code. */
    public function setPortLinkSettings(int $port, bool $autoNegotiation, ?int $speedCode): void
    {
        if ($port < 1 || $port > 32) throw new RuntimeException('Portnummer außerhalb des gültigen Bereichs.');
        if (!$autoNegotiation && ($speedCode === null || $speedCode < 0 || $speedCode > 15)) {
            throw new RuntimeException('Bitte eine gültige Portgeschwindigkeit auswählen.');
        }
        $raw = $this->request('/link.b');
        $raw = $this->updateMaskValue($raw, ['an', 'autoneg', 'auto'], $port - 1, $autoNegotiation);
        if (!$autoNegotiation && $speedCode !== null) {
            $raw = $this->updateArrayValue($raw, ['spdc', 'sp', 'speedc'], $port - 1, '0x' . str_pad(dechex($speedCode), 2, '0', STR_PAD_LEFT));
            try { $raw = $this->updateMaskValue($raw, ['dpxc', 'fd', 'fdx'], $port - 1, true); } catch (Throwable) {}
        }
        $this->request('/link.b', 'POST', $raw, ['Content-Type: text/plain'], 15);
    }

    /** @return list<array<string, mixed>> Normalized `/lacp.b` port rows. */
    public function getLagConfiguration(): array
    {
        [, $data] = $this->endpointPayload('/lacp.b');
        $count = 0;
        foreach (['mode', 'sgrp'] as $key) if (isset($data[$key]) && is_array($data[$key])) $count = max($count, count($data[$key]));
        $rows = [];
        $labels = [0 => 'Passive LACP', 1 => 'Active LACP', 2 => 'Static'];
        for ($i = 0; $i < $count; $i++) {
            $mode = $this->parseNumber($this->indexedValue($data, ['mode', 'md'], $i, 0));
            $rows[] = [
                'port' => $i + 1,
                'mode' => $mode,
                'mode_label' => $labels[$mode] ?? ('Modus ' . $mode),
                'group' => $this->parseNumber($this->indexedValue($data, ['sgrp'], $i, 0)),
                'trunk' => $this->parseNumber($this->indexedValue($data, ['trunk', 'trk'], $i, 0)),
                'partner' => $this->hexToString((string)$this->indexedValue($data, ['partner', 'prt'], $i, '')),
            ];
        }
        return $rows;
    }

    /** Updates one port's LACP mode and static group in `/lacp.b`. */
    public function setLagPort(int $port, int $mode, int $group): void
    {
        if ($port < 1 || $port > 64 || $mode < 0 || $mode > 2 || $group < 0 || $group > 16) throw new RuntimeException('Ungültige LAG-Einstellung.');
        $raw = $this->request('/lacp.b');
        $raw = $this->updateArrayValue($raw, ['mode'], $port - 1, '0x' . str_pad(dechex($mode), 2, '0', STR_PAD_LEFT));
        $raw = $this->updateArrayValue($raw, ['sgrp'], $port - 1, '0x' . str_pad(dechex($group), 2, '0', STR_PAD_LEFT));
        $this->request('/lacp.b', 'POST', $raw, ['Content-Type: text/plain'], 15);
    }

    /**
     * Reads port VLAN settings from `/fwd.b` and table entries from `/vlan.b`.
     *
     * @return array{ports: list<array<string, mixed>>, vlans: list<array<string, mixed>>, port_count: int}
     */
    public function getVlanConfiguration(): array
    {
        // Auf CRS3xx mit SwOS 2.x liegen die Einstellungen des Tabs "VLAN"
        // in fwd.b. vlan.b enthält dagegen die Einträge des Tabs "VLANs".
        [, $portData] = $this->endpointPayload('/fwd.b');
        $portCount = 0;
        foreach (['vlan', 'vlni', 'dvid'] as $key) if (isset($portData[$key]) && is_array($portData[$key])) $portCount = max($portCount, count($portData[$key]));
        $ports = [];
        for ($i = 0; $i < $portCount; $i++) {
            $ports[] = [
                'port' => $i + 1,
                'pvid' => $this->parseNumber($this->indexedValue($portData, ['dvid'], $i, 1)),
                'mode' => $this->parseNumber($this->indexedValue($portData, ['vlan'], $i, 1)),
                'receive' => $this->parseNumber($this->indexedValue($portData, ['vlni'], $i, 0)),
                'force_vlan' => $this->maskEnabled($portData, ['fvid'], $i, false),
            ];
        }

        [, $tableData] = $this->endpointPayload('/vlan.b', true);
        if (!array_is_list($tableData)) throw new RuntimeException('vlan.b enthält nicht das erwartete CRS3xx-Tabellenformat.');
        $vlans = [];
        foreach ($tableData as $i => $row) {
            if (!is_array($row)) continue;
            $vlans[] = [
                'index' => $i,
                'id' => $this->parseNumber($row['vid'] ?? 0),
                'name' => $this->hexToString((string)($row['nm'] ?? '')),
                'members_mask' => $this->parseNumber($row['mbr'] ?? 0),
            ];
        }
        return ['ports' => $ports, 'vlans' => $vlans, 'port_count' => $portCount];
    }

    /** Updates PVID, VLAN mode, receive mode and force-VLAN state for one port. */
    public function setVlanPort(int $port, int $pvid, int $mode, int $receive, bool $force): void
    {
        if ($port < 1 || $port > 64 || $pvid < 1 || $pvid > 4095 || $mode < 0 || $mode > 3 || $receive < 0 || $receive > 2) throw new RuntimeException('Ungültige Port-VLAN-Einstellung.');
        $raw = $this->request('/fwd.b');
        $raw = $this->updateArrayValue($raw, ['dvid'], $port - 1, '0x' . str_pad(dechex($pvid), 4, '0', STR_PAD_LEFT));
        $raw = $this->updateArrayValue($raw, ['vlan'], $port - 1, '0x' . str_pad(dechex($mode), 2, '0', STR_PAD_LEFT));
        $raw = $this->updateArrayValue($raw, ['vlni'], $port - 1, '0x' . str_pad(dechex($receive), 2, '0', STR_PAD_LEFT));
        $raw = $this->updateMaskValue($raw, ['fvid'], $port - 1, $force);
        $this->request('/fwd.b', 'POST', $raw, ['Content-Type: text/plain'], 15);
    }

    /**
     * Creates or replaces one VLAN table row and verifies the written VLAN ID.
     * A null index appends a new row; existing rows use a zero-based index.
     */
    public function saveVlan(?int $index, int $vlanId, string $name, int $membersMask): void
    {
        if ($vlanId < 1 || $vlanId > 4094) throw new RuntimeException('VLAN-ID muss zwischen 1 und 4094 liegen.');
        if (strlen($name) > 32) throw new RuntimeException('VLAN-Name darf höchstens 32 Bytes lang sein.');
        [, $rows] = $this->endpointPayload('/vlan.b', true);
        if (!array_is_list($rows)) throw new RuntimeException('vlan.b enthält nicht das erwartete CRS3xx-Tabellenformat.');
        $rowCount = count($rows);
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex !== $index && $this->parseNumber($row['vid'] ?? 0) === $vlanId) throw new RuntimeException('VLAN ' . $vlanId . ' ist bereits vorhanden.');
        }
        if ($index === null) {
            $index = $rowCount;
            $rows[] = ['nm' => '', 'mbr' => 0, 'vid' => 1, 'piso' => 1, 'lrn' => 1, 'mrr' => 0, 'igmp' => 0];
        } elseif ($index < 0 || $index >= $rowCount) {
            throw new RuntimeException('VLAN-Eintrag wurde nicht gefunden.');
        }
        $rows[$index]['nm'] = bin2hex($name);
        $rows[$index]['mbr'] = $membersMask & 0xffffffff;
        $rows[$index]['vid'] = $vlanId;
        $this->request('/vlan.b', 'POST', $this->serializeVlanTable($rows), ['Content-Type: text/plain'], 20);

        // A successful HTTP response does not guarantee that SwOS accepted
        // the VID. Read it back so the UI never reports a false success.
        [, $savedRows] = $this->endpointPayload('/vlan.b', true);
        $savedId = is_array($savedRows) && isset($savedRows[$index]) && is_array($savedRows[$index])
            ? $this->parseNumber($savedRows[$index]['vid'] ?? 0)
            : 0;
        if ($savedId !== $vlanId) {
            throw new RuntimeException('SwOS hat VLAN-ID ' . $vlanId . ' nicht übernommen (zurückgelesen: ' . $savedId . ').');
        }
    }

    /** Deletes a zero-based VLAN row and serializes the remaining table. */
    public function deleteVlan(int $index): void
    {
        [, $rows] = $this->endpointPayload('/vlan.b', true);
        if (!array_is_list($rows)) throw new RuntimeException('vlan.b enthält nicht das erwartete CRS3xx-Tabellenformat.');
        $rowCount = count($rows);
        if ($index < 0 || $index >= $rowCount) throw new RuntimeException('VLAN-Eintrag wurde nicht gefunden.');
        array_splice($rows, $index, 1);
        $this->request('/vlan.b', 'POST', $this->serializeVlanTable($rows), ['Content-Type: text/plain'], 20);
    }
}
