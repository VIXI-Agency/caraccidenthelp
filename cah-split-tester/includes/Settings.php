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
    public const DEFAULT_COOKIE_TTL_DAYS = 60;

    public function defaults(): array
    {
        return [
            'make_webhook_url'         => self::DEFAULT_MAKE_WEBHOOK,
            'cookie_ttl_days'          => self::DEFAULT_COOKIE_TTL_DAYS,
            'ip_hash_salt'             => '',
            'drop_tables_on_uninstall' => false,
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

    public function update(array $partial): bool
    {
        $merged = \array_merge($this->all(), $partial);
        return \update_option(self::OPTION_KEY, $merged);
    }
}
