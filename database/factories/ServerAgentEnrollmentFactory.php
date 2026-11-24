<?php

namespace Database\Factories;

use App\Models\ServerAgentEnrollment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServerAgentEnrollment>
 */
class ServerAgentEnrollmentFactory extends Factory
{
    protected $model = ServerAgentEnrollment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'token_hash' => ServerAgentEnrollment::hashToken(Str::random(48)),
            'team_id' => Team::factory(),
            'hostname' => 'srv-'.Str::lower(Str::random(10)).'.nodes.example.test',
            'cloudflare_tunnel_id' => (string) Str::uuid(),
            'cloudflare_dns_record_id' => Str::random(32),
            'tunnel_token' => Str::random(64),
            'status' => ServerAgentEnrollment::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ];
    }
}
