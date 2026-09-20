<?php

namespace App\Http\Controllers;

use App\Mandal\CliArchive;
use App\Mandal\SetupScript;
use App\Models\Server;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the setup script and CLI archive that a server fetches while it is
 * being set up. Both are protected by the server's single-use setup token.
 */
class ServerSetupController extends Controller
{
    public function script(Server $server, string $token, SetupScript $script, CliArchive $archive): Response
    {
        abort_unless($server->setupTokenMatches($token), 404);

        $body = $script->render(
            $server,
            cliUrl: route('setup.cli', ['server' => $server, 'token' => $token]),
            version: $archive->version(),
        );

        return response($body, headers: ['Content-Type' => 'text/x-shellscript; charset=utf-8']);
    }

    public function cli(Server $server, string $token, CliArchive $archive): BinaryFileResponse
    {
        abort_unless($server->setupTokenMatches($token), 404);

        return response()->download($archive->path(), 'mandal-cli.tar.gz', ['Content-Type' => 'application/gzip']);
    }
}
