<?php

namespace App\Domain\Odoo;

use Illuminate\Support\Facades\DB;

/**
 * The instance SMTP, written into that Odoo service's .env.
 * Empty SMTP lines when SMTP is off: Odoo speaks SMTP, so Resend-only mail is left alone.
 * The daily cap is still written. A neutralized database does not send.
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
    public static function mailEnvironmentLines(?object $settings = null, ?int $teamId = null): array
    {
        $settings ??= instanceSettings();
        $lines = self::quotaLines($settings, $teamId);
        if (! ($settings->smtp_enabled ?? false)) {
            return $lines;
        }
        $host = trim((string) ($settings->smtp_host ?? ''));
        if ($host === '' || preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $host) !== 1) {
            return $lines;
        }
        $port = (int) ($settings->smtp_port ?? 25);

        return [
            ...$lines,
            self::dotenvLine('GPSH_SMTP_HOST', $host),
            self::dotenvLine('GPSH_SMTP_PORT', (string) ($port >= 1 && $port <= 65535 ? $port : 25)),
            self::dotenvLine('GPSH_SMTP_ENCRYPTION', self::odooSmtpEncryption($settings->smtp_encryption ?? null)),
            self::dotenvLine('GPSH_SMTP_USER', (string) ($settings->smtp_username ?? '')),
            self::dotenvLine('GPSH_SMTP_PASSWORD', (string) ($settings->smtp_password ?? '')),
            self::dotenvLine('GPSH_SMTP_FROM', trim((string) ($settings->smtp_from_address ?? ''))),
        ];
    }

    public static function limit(?object $settings = null): int
    {
        $settings ??= instanceSettings();

        return max(0, min(10000, (int) ($settings->odoo_mail_daily_limit ?? 20)));
    }

    public static function token(int $teamId): string
    {
        return hash_hmac('sha256', 'mail|'.$teamId, (string) config('app.key'));
    }

    public static function takeSlot(int $teamId): bool
    {
        $limit = self::limit();
        if ($teamId < 0 || $limit < 1) {
            return false;
        }
        $day = now()->toDateString();

        return DB::transaction(function () use ($teamId, $day, $limit): bool {
            $row = DB::table('gpsh_mail_counts')
                ->where('team_id', $teamId)
                ->where('sent_on', $day)
                ->lockForUpdate()
                ->first();
            $sent = (int) ($row->sent ?? 0);
            if ($sent >= $limit) {
                return false;
            }
            if ($row === null) {
                DB::table('gpsh_mail_counts')->insert([
                    'team_id' => $teamId,
                    'sent_on' => $day,
                    'sent' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('gpsh_mail_counts')->where('id', $row->id)->update([
                    'sent' => $sent + 1,
                    'updated_at' => now(),
                ]);
            }

            return true;
        });
    }

    /**
     * @return list<string>
     */
    private static function quotaLines(?object $settings, ?int $teamId): array
    {
        $lines = [self::dotenvLine('GPSH_MAIL_LIMIT', (string) self::limit($settings))];
        if ($teamId === null || $teamId < 0) {
            return $lines;
        }
        $url = self::quotaUrl();
        $lines[] = self::dotenvLine('GPSH_TEAM_ID', (string) $teamId);
        $lines[] = self::dotenvLine('GPSH_MAIL_TOKEN', self::token($teamId));
        if ($url !== '') {
            $lines[] = self::dotenvLine('GPSH_MAIL_URL', $url);
        }

        return $lines;
    }

    private static function quotaUrl(): string
    {
        $base = trim((string) (instanceSettings()->fqdn ?? ''));
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }
        if ($base !== '' && ! str_contains($base, '://')) {
            $base = 'https://'.$base;
        }
        $base = rtrim($base, '/');
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::\d+)?\z#', $base) !== 1) {
            return '';
        }

        return $base.'/gpsh/mail-quota';
    }

    private static function dotenvLine(string $key, string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $value = str_replace('$', '$$', $value);

        return $key.'="'.$value.'"';
    }
}
