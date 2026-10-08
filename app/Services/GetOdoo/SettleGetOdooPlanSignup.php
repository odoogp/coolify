<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlanSignup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SettleGetOdooPlanSignup
{
    public function __construct(private OpenGetOdooPlanAccount $accounts) {}

    public function settle(GetOdooPlanSignup $signup, string $transactionId): User
    {
        return DB::transaction(function () use ($signup, $transactionId) {
            $signup = GetOdooPlanSignup::query()->whereKey($signup->id)->lockForUpdate()->firstOrFail();
            $signup->loadMissing(['plan', 'pricingArea']);

            if ($signup->status === 'paid') {
                $signup->load('user');

                if ($signup->user instanceof User) {
                    return $signup->user;
                }
            }

            if ($signup->status !== 'awaiting_payment' || $signup->plan === null || ! is_string($signup->password) || $signup->password === '') {
                throw new RuntimeException('This signup cannot be paid.');
            }

            $user = $this->accounts->open(
                $signup->name,
                $signup->email,
                $signup->password,
                $signup->plan,
                $signup->pricingArea,
            );

            $signup->update([
                'status' => 'paid',
                'user_id' => $user->id,
                'wompi_transaction_id' => $transactionId,
                'password' => null,
                'wompi_link_url' => null,
            ]);

            return $user;
        });
    }
}
