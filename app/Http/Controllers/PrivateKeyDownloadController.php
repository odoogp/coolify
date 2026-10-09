<?php

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateKeyDownloadController extends Controller
{
    public function __invoke(string $private_key_uuid): StreamedResponse
    {
        $privateKey = PrivateKey::ownedByCurrentTeam()
            ->where('uuid', $private_key_uuid)
            ->firstOrFail();

        $this->authorize('update', $privateKey);

        $filename = str($privateKey->name)->slug()->toString();
        if ($filename === '') {
            $filename = $privateKey->uuid;
        }
        $filename .= '.key';

        $contents = $privateKey->private_key;

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
