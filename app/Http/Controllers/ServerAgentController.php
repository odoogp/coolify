<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateAndInstallServerJob;
use App\Models\ServerAgentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public endpoints the client's install command talks to. Both are gated only by
 * the one-time enrollment token, which expires and stops working once connected.
 */
class ServerAgentController extends Controller
{
    public function install(string $token): Response
    {
        $enrollment = ServerAgentEnrollment::findUsableByToken($token);
        abort_unless($enrollment instanceof ServerAgentEnrollment, 404);

        $server = $enrollment->server;
        // Only key and token characters reach the shell script, never quotes or spaces beyond the key type.
        $publicKey = trim((string) $server?->privateKey?->getPublicKey());
        $publicKey = (string) preg_replace('/\A(ssh-(?:ed25519|rsa) [A-Za-z0-9+\/=]+).*\z/s', '$1', $publicKey);
        $tunnelToken = (string) $enrollment->tunnel_token;
        abort_unless(preg_match('/\Assh-(?:ed25519|rsa) [A-Za-z0-9+\/=]+\z/', $publicKey) === 1, 404);
        abort_unless(preg_match('/\A[A-Za-z0-9+\/=_-]+\z/', $tunnelToken) === 1, 404);

        $script = view('agent.install', [
            'enrollment' => $enrollment,
            'publicKey' => $publicKey,
            'tunnelToken' => $tunnelToken,
            'readyUrl' => route('agent.ready', ['token' => $token]),
            'cloudflaredImage' => self::cloudflaredImage(),
        ])->render();

        return response($script, 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function ready(Request $request, string $token): JsonResponse
    {
        $enrollment = ServerAgentEnrollment::findUsableByToken($token);
        abort_unless($enrollment instanceof ServerAgentEnrollment, 404);

        $publicIp = trim((string) $request->input('public_ip', ''));
        $server = $enrollment->server;
        if (filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $server->update(['public_ip' => $publicIp]);
        }

        $enrollment->update([
            'status' => ServerAgentEnrollment::STATUS_REPORTED,
            'reported_at' => now(),
            'error' => null,
        ]);

        // The tunnel needs a few seconds to register at Cloudflare before SSH answers.
        ValidateAndInstallServerJob::dispatch($server->fresh())->delay(now()->addSeconds(15));

        return response()->json(['ok' => true]);
    }

    public static function cloudflaredImage(): string
    {
        return 'cloudflare/cloudflared:2025.4.0';
    }
}
