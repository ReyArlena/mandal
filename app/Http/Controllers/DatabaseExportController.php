<?php

namespace App\Http\Controllers;

use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a dump of one database from the server to the browser through
 * `mandal db:export`, as plain SQL or zstd-compressed like the backups.
 * The file is named like a backup, so it imports back the same way.
 */
class DatabaseExportController extends Controller
{
    public const string DATABASE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    public function __invoke(Server $server, string $database, string $format, MandalClient $mandal): StreamedResponse
    {
        abort_unless(preg_match(self::DATABASE_PATTERN, $database) && in_array($format, ['sql', 'zst'], true), 404);
        abort_unless($server->isProvisioned(), 404);

        $file = $database.'-'.now()->utc()->format('YmdHis').($format === 'zst' ? '.sql.zst' : '.sql');

        $headers = [
            'Content-Type' => $format === 'zst' ? 'application/zstd' : 'application/sql; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ];

        return new StreamedResponse(function () use ($mandal, $server, $database, $format, $file): void {
            set_time_limit(0);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                $result = $mandal->stream($server, "db:export --name={$database} --format={$format}", function (string $chunk): void {
                    echo $chunk;
                    flush();
                });

                if ($result->failed()) {
                    Log::warning("Export of {$file} from {$server->name} failed: ".trim($result->errorOutput));
                }
            } catch (SshException $exception) {
                Log::warning("Export of {$file} from {$server->name} stopped: ".$exception->getMessage());
            }
        }, 200, $headers);
    }
}
