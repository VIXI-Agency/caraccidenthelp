<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION_KEY = 'cah_split_settings';

    public const DEFAULT_MAKE_WEBHOOK    = 'https://hook.us2.make.com/bq4dng1sofubjsweiw8w46deyryw5f9b';
    public const DEFAULT_COOKIE_TTL_DAYS = 30;

    /** Special value: when stored as the dashboard timezone, defer to the
     *  WordPress site timezone (Settings → General). Any other value is
     *  treated as a literal IANA name (e.g. 'America/Chicago') or a fixed
     *  offset accepted by DateTimeZone (e.g. '+00:00'). */
    public const DASHBOARD_TZ_SITE = 'site';

    /** Allowed dashboard timezone values. Any value passed to update() outside
     *  this list (and not a valid DateTimeZone identifier) is rejected back to
     *  the site default. The list is intentionally short — we surface the US
     *  zones the client actually operates in plus UTC for raw inspection. */
    public const DASHBOARD_TZ_CHOICES = [
        self::DASHBOARD_TZ_SITE => 'WordPress site timezone (default)',
        'UTC'                   => 'UTC',
        'America/New_York'      => 'Eastern (America/New_York)',
        'America/Chicago'       => 'Central (America/Chicago)',
        'America/Denver'        => 'Mountain (America/Denver)',
        'America/Phoenix'       => 'Arizona / no DST (America/Phoenix)',
        'America/Los_Angeles'   => 'Pacific (America/Los_Angeles)',
        'America/Anchorage'     => 'Alaska (America/Anchorage)',
        'Pacific/Honolulu'      => 'Hawaii (Pacific/Honolulu)',
    ];

    public function defaults(): array
    {
        return [
            'make_webhook_url'         => self::DEFAULT_MAKE_WEBHOOK,
            'cookie_ttl_days'          => self::DEFAULT_COOKIE_TTL_DAYS,
            'ip_hash_salt'             => '',
            'drop_tables_on_uninstall' => false,
            'dashboard_timezone'       => self::DASHBOARD_TZ_SITE,
        ];
    }

    public function all(): array
    {
        $stored = \get_option(self::OPTION_KEY, []);
        if (!\is_array($stored)) {
            $stored = [];
        }
        return \array_merge($this->defaults(), $stored);
    }

    public function makeWebhookUrl(): string
    {
        return (string) $this->all()['make_webhook_url'];
    }

    public function cookieTtlDays(): int
    {
        $days = (int) $this->all()['cookie_ttl_days'];
        return $days > 0 ? $days : self::DEFAULT_COOKIE_TTL_DAYS;
    }

    public function cookieTtlSeconds(): int
    {
        return $this->cookieTtlDays() * DAY_IN_SECONDS;
    }

    public function ipHashSalt(): string
    {
        $salt = (string) $this->all()['ip_hash_salt'];
        if ($salt === '') {
            $salt = \wp_generate_password(64, false);
            $this->update(['ip_hash_salt' => $salt]);
        }
        return $salt;
    }

    public function dropTablesOnUninstall(): bool
    {
        return (bool) $this->all()['drop_tables_on_uninstall'];
    }

    /**
     * Stored timezone preference (raw). Could be 'site', a known IANA name
     * (e.g. 'America/Chicago'), or 'UTC'. Empty / unknown values fall back
     * to 'site'. Use dashboardTimezone() instead to get a resolved
     * \DateTimeZone instance suitable for date math.
     */
    public function dashboardTimezoneRaw(): string
    {
        $value = (string) $this->all()['dashboard_timezone'];
        if ($value === '') {
            return self::DASHBOARD_TZ_SITE;
        }
        return $value;
    }

    /**
     * Resolve the dashboard timezone to a \DateTimeZone instance. If the
     * preference is 'site' or invalid we defer to wp_timezone() (which
     * respects Settings → General → Timezone, including manual offsets).
     * Any other stored value is parsed as an IANA name. If parsing fails
     * (e.g. typo, removed zone) we silently fall back to the site zone
     * rather than throwing in admin views.
     */
    public function dashboardTimezone(): \DateTimeZone
    {
        $raw = $this->dashboardTimezoneRaw();
        if ($raw === self::DASHBOARD_TZ_SITE) {
            return \function_exists('wp_timezone') ? \wp_timezone() : new \DateTimeZone('UTC');
        }
        try {
            return new \DateTimeZone($raw);
        } catch (\Throwable $e) {
            return \function_exists('wp_timezone') ? \wp_timezone() : new \DateTimeZone('UTC');
        }
    }

    public function update(array $partial): bool
    {
        $merged = \array_merge($this->all(), $partial);
        return \update_option(self::OPTION_KEY, $merged);
    }
}
