<?php

namespace App\Http\Controllers;

use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one app archive from the server to the browser through
 * `mandal app:backup-get`, chunk by chunk, so a multi-gigabyte archive
 * never has to fit in the panel's memory.
 */
class AppBackupDownloadController extends Controller
{
    public const string APP_PATTERN = '/^[a-z][a-z0-9-]{1,31}$/';

    public const string FILE_PATTERN = '/^[a-z][a-z0-9-]{1,31}-[0-9]{14}\.tar\.zst$/';

    public function __invoke(Server $server, string $app, string $file, MandalClient $mandal): StreamedResponse
    {
        abort_unless(preg_match(self::APP_PATTERN, $app) && preg_match(self::FILE_PATTERN, $file), 404);
        abort_unless($server->isProvisioned(), 404);

        // The list confirms the file exists before any headers go out, and
        // its size lets the browser show progress.
        try {
            $list = $mandal->run($server, 'app:backup-list --name='.$app, timeoutSeconds: 60);
            $entry = $list->successful() ? collect($list->json()['backups'] ?? [])->firstWhere('file', $file) : null;
        } catch (SshException $exception) {
            abort(502, $exception->getMessage());
        }

        abort_unless(is_array($entry), 404);

        $headers = [
            'Content-Type' => 'application/zstd',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ];

        if (is_int($entry['size'] ?? null) && $entry['size'] > 0) {
            $headers['Content-Length'] = (string) $entry['size'];
        }

        return new StreamedResponse(function () use ($mandal, $server, $app, $file): void {
            set_time_limit(0);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                $result = $mandal->stream($server, "app:backup-get --name={$app} --file={$file}", function (string $chunk): void {
                    echo $chunk;
                    flush();
                });

                if ($result->failed()) {
                    Log::warning("Download of {$file} from {$server->name} failed: ".trim($result->errorOutput));
                }
            } catch (SshException $exception) {
                Log::warning("Download of {$file} from {$server->name} stopped: ".$exception->getMessage());
            }
        }, 200, $headers);
    }
}
