<?php

declare(strict_types=1);

/**
 * Canonical public product metadata used by the UI and integrations.
 *
 * Release automation also verifies these values against VERSION and the
 * container label, so a version bump remains consistent across artifacts.
 */
final class AppInfo
{
    public const NAME = 'Switchly';
    public const VERSION = '1.4.7';
    public const CHANNEL = 'Beta';
    public const DISPLAY_VERSION = 'v1.4.7 Beta';
    public const USER_AGENT = 'Switchly/1.4.7-beta';

    /** @return array{name: string, version: string, channel: string, display_version: string} */
    public static function publicInfo(): array
    {
        return [
            'name' => self::NAME,
            'version' => self::VERSION,
            'channel' => self::CHANNEL,
            'display_version' => self::DISPLAY_VERSION,
        ];
    }
}
