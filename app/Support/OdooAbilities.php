<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class OdooAbilities
{
    /** @var list<string> */
    public const ALL = [
        'odoo.project.view',
        'odoo.project.update',
        'odoo.repository.configure',
        'odoo.staging.deploy',
        'odoo.production.deploy',
        'odoo.staging.sync',
        'odoo.backup.create',
        'odoo.backup.restore',
    ];

    /** @var list<string> */
    public const GRANTABLE = [
        'odoo.staging.deploy',
        'odoo.production.deploy',
        'odoo.staging.sync',
        'odoo.backup.create',
        'odoo.backup.restore',
    ];

    public static function allows(User $user, int $teamId, string $ability): bool
    {
        if (! in_array($ability, self::ALL, true)) {
            return false;
        }

        $role = $user->roleInTeam($teamId);
        if ($role === 'owner' || $role === 'admin') {
            return true;
        }

        if ($role !== 'member') {
            return false;
        }

        if ($ability === 'odoo.project.view') {
            return true;
        }

        return in_array($ability, self::grants($user->id, $teamId), true);
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function onlyGrantable(array $abilities): array
    {
        return array_values(array_intersect(self::GRANTABLE, $abilities));
    }

    /**
     * @return list<string>
     */
    public static function grants(int $userId, int $teamId): array
    {
        $raw = DB::table('team_user')
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->value('odoo_abilities');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? self::onlyGrantable(array_map('strval', $decoded)) : [];
    }
}
