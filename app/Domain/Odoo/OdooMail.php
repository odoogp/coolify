<?php

namespace App\Domain\Odoo;

/**
 * The instance SMTP, written into that Odoo service's .env.
 * Empty when SMTP is off: Odoo speaks SMTP, so Resend-only mail is left alone.
 */
class OdooMail
{
    /**
     * Odoo encryption names. Coolify stores implicit TLS as "tls".
     */
    public static function odooSmtpEncryption(?string $mode): string
    {
        return match (strtolower((string) $mode)) {
            'starttls' => 'starttls',
            'tls', 'ssl' => 'ssl',
            default => 'none',
        };
    }

    /**
     * @return list<string>
     */
    public static function mailEnvironmentLines(?object $settings = null): array
    {
        $settings ??= instanceSettings();
        if (! ($settings->smtp_enabled ?? false)) {
            return [];
        }
        $host = trim((string) ($settings->smtp_host ?? ''));
        if ($host === '' || preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $host) !== 1) {
            return [];
        }
        $port = (int) ($settings->smtp_port ?? 25);

        return [
            self::dotenvLine('GPSH_SMTP_HOST', $host),
            self::dotenvLine('GPSH_SMTP_PORT', (string) ($port >= 1 && $port <= 65535 ? $port : 25)),
            self::dotenvLine('GPSH_SMTP_ENCRYPTION', self::odooSmtpEncryption($settings->smtp_encryption ?? null)),
            self::dotenvLine('GPSH_SMTP_USER', (string) ($settings->smtp_username ?? '')),
            self::dotenvLine('GPSH_SMTP_PASSWORD', (string) ($settings->smtp_password ?? '')),
            self::dotenvLine('GPSH_SMTP_FROM', trim((string) ($settings->smtp_from_address ?? ''))),
        ];
    }

    private static function dotenvLine(string $key, string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $value = str_replace('$', '$$', $value);

        return $key.'="'.$value.'"';
    }
}
