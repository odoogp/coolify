<?php

namespace App\Actions\Server;

use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerAgentEnrollment;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloudflare\CloudflareTunnelApi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * "Connect your server with one command": creates the client server (reached over
 * SSH through a Cloudflare tunnel), its SSH key, the tunnel itself and a one-time
 * install token. The client then runs the returned command on their server.
 */
class StartServerAgentEnrollment
{
    use AsAction;

    public const TOKEN_TTL_HOURS = 24;

    /**
     * @return array{enrollment: ServerAgentEnrollment, command: string, token: string}
     */
    public function handle(User $user, Team $team, string $name): array
    {
        if (! $user->can('create', Server::class)) {
            throw new AuthorizationException(__('You cannot connect servers.'));
        }
        if (Team::serverLimitReached($team)) {
            throw new RuntimeException(__('You have reached the server limit for your subscription.'));
        }

        $cloudflare = CloudflareTunnelApi::fromSettings();
        if (! $cloudflare instanceof CloudflareTunnelApi) {
            throw new RuntimeException(__('Connecting servers with a command is not available yet.'));
        }

        $label = 'srv-'.Str::lower(Str::random(12));
        $hostname = $cloudflare->hostnameFor($label);
        $token = Str::random(48);

        $tunnel = $cloudflare->createTunnel('getcloud-'.$label);
        $dnsRecordId = null;

        try {
            $cloudflare->routeSsh($tunnel['id'], $hostname);
            $dnsRecordId = $cloudflare->createDns($hostname, $tunnel['id']);

            $enrollment = DB::transaction(function () use ($user, $team, $name, $hostname, $tunnel, $dnsRecordId, $token): ServerAgentEnrollment {
                $keyPair = PrivateKey::generateNewKeyPair('ed25519');
                $privateKey = PrivateKey::createAndStore([
                    'name' => Str::limit('GetCloud agent '.$name, 250, ''),
                    'description' => __('Created when connecting a server with a command.'),
                    'private_key' => $keyPair['private_key'],
                    'team_id' => $team->id,
                ]);

                $server = Server::create([
                    'name' => $name,
                    'description' => __('Connected with the GetCloud agent'),
                    'ip' => $hostname,
                    'user' => 'root',
                    'port' => 22,
                    'team_id' => $team->id,
                    'private_key_id' => $privateKey->id,
                ]);
                $server->proxy->set('status', 'exited');
                $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
                $server->save();
                $server->settings->is_cloudflare_tunnel = true;
                $server->settings->save();

                return ServerAgentEnrollment::create([
                    'uuid' => (string) Str::uuid(),
                    'token_hash' => ServerAgentEnrollment::hashToken($token),
                    'team_id' => $team->id,
                    'server_id' => $server->id,
                    'created_by' => $user->id,
                    'hostname' => $hostname,
                    'cloudflare_tunnel_id' => $tunnel['id'],
                    'cloudflare_dns_record_id' => $dnsRecordId,
                    'tunnel_token' => $tunnel['token'],
                    'status' => ServerAgentEnrollment::STATUS_PENDING,
                    'expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
                ]);
            });
        } catch (Throwable $exception) {
            try {
                $cloudflare->delete($tunnel['id'], $dnsRecordId);
            } catch (Throwable) {
                // Cleanup is best effort; the original error is what the person needs to see.
            }

            throw $exception;
        }

        return [
            'enrollment' => $enrollment,
            'command' => self::command($token),
            'token' => $token,
        ];
    }

    public static function command(string $token): string
    {
        return 'curl -fsSL '.escapeshellarg(route('agent.install', ['token' => $token])).' | sudo bash';
    }
}
