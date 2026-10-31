<?php

namespace App\Jobs;

use App\Mandal\MandalClient;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Math\BigInteger;

/**
 * Times what the panel spends on a server: its own setup, the SSH
 * connection, the inventory over SSH and from the cache, then the CLI's
 * `mandal benchmark`. Writes a readable report and a JSON block to share.
 */
class RunBenchmark extends RunMandalTask
{
    public function arguments(): string
    {
        return 'benchmark';
    }

    /**
     * The panel's setup as the web server runs it. Captured when the button
     * is pressed, since the queue worker's PHP (opcache, ini) is not the
     * one that serves pages.
     *
     * @return array<string, mixed>
     */
    public static function panelEnvironment(): array
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $views = glob(rtrim((string) config('view.compiled'), '/').'/*.php');

        return [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'opcache' => is_array($opcache) ? (bool) ($opcache['opcache_enabled'] ?? false) : (bool) ini_get('opcache.enable'),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'events_cached' => app()->eventsAreCached(),
            'views_compiled' => $views === false ? 0 : count($views),
            'debug' => (bool) config('app.debug'),
            'environment' => app()->environment(),
            'cache' => config('cache.default'),
            'session' => config('session.driver'),
            'queue' => config('queue.default'),
            'database' => config('database.default'),
            'extensions' => collect(['sodium', 'gmp', 'bcmath', 'openssl'])->mapWithKeys(fn (string $name): array => [$name => extension_loaded($name)])->all(),
            'phpseclib_math' => implode('/', BigInteger::getEngine()),
        ];
    }

    public function handle(MandalClient $mandal): void
    {
        $ssh = app(SshClient::class);
        $task = $this->task;
        $server = $task->server;
        $report = ['panel' => $task->options['panel'] ?? []];

        $task->markRunning();
        $task->appendOutput($this->panelSection($report['panel']));

        try {
            $task->appendOutput("\nSSH ({$server->ssh_user}@{$server->host}:{$server->port}, from the queue worker)\n");
            $report['ssh'] = $this->ssh($ssh, $mandal);
            $task->appendOutput($this->rows([
                'Connect (TCP, key exchange, login)' => $report['ssh']['connect'],
                'Round trip of a no-op (best of 5)' => $report['ssh']['round_trip'],
                'mandal version as '.$server->ssh_user.' (best of 3)' => $report['ssh']['version'],
                'mandal version through sudo (best of 3)' => $report['ssh']['version_sudo'],
                'sudo overhead' => $report['ssh']['sudo_overhead'],
            ]));

            $task->appendOutput("\nInventory\n");
            $report['inventory'] = $this->inventory($mandal);
            $task->appendOutput($this->rows([
                'Fresh, over a new SSH connection' => $report['inventory']['fresh'],
                'From the panel cache ('.config('cache.default').')' => $report['inventory']['cached'],
            ]));

            $task->appendOutput("\nCLI on the server (mandal benchmark, up to a minute)\n");
            $result = $mandal->run($server, $this->arguments(), timeoutSeconds: $this->timeout - 60);
        } catch (SshException $exception) {
            $task->appendOutput("\nerror: {$exception->getMessage()}\n");
            $task->markFinished(255);

            return;
        }

        if ($result->failed()) {
            $task->appendOutput(trim($result->errorOutput ?: $result->output)."\n");
            $task->markFinished($result->exitCode);

            return;
        }

        $report['cli'] = $result->json();
        $task->appendOutput($this->cliSection($report));
        $task->appendOutput("\nJSON\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $task->markFinished(0);
    }

    /**
     * @return array<string, float>
     */
    private function ssh(SshClient $ssh, MandalClient $mandal): array
    {
        $server = $this->task->server;

        $start = hrtime(true);
        $connection = $ssh->connect($server);
        $connect = $this->since($start);

        try {
            $roundTrip = $this->best(5, fn () => $connection->run('true', timeoutSeconds: 15));
            $version = $this->best(3, fn () => $connection->run(MandalClient::BINARY.' version --json', timeoutSeconds: 15));
            $sudo = $this->best(3, fn () => $connection->run('sudo -n '.MandalClient::BINARY.' version --json', timeoutSeconds: 15));
        } finally {
            $connection->disconnect();
        }

        return [
            'connect' => $connect,
            'round_trip' => $roundTrip,
            'version' => $version,
            'version_sudo' => $sudo,
            'sudo_overhead' => round(max(0, $sudo - $version), 3),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function inventory(MandalClient $mandal): array
    {
        $server = $this->task->server;

        $start = hrtime(true);
        $mandal->inventory($server, fresh: true);
        $fresh = $this->since($start);

        $start = hrtime(true);
        Cache::get("servers.{$server->getKey()}.inventory");
        $cached = $this->since($start);

        return ['fresh' => $fresh, 'cached' => $cached];
    }

    /**
     * @param  array<string, mixed>  $panel
     */
    private function panelSection(array $panel): string
    {
        if ($panel === []) {
            return "Panel\n  (not recorded)\n";
        }

        $yes = fn (mixed $value): string => $value ? 'yes' : 'no';
        $extensions = collect($panel['extensions'] ?? [])->map(fn (bool $loaded, string $name): string => $name.' '.$yes($loaded))->implode(', ');

        $lines = [
            'PHP' => ($panel['php'] ?? '?').' ('.($panel['sapi'] ?? '?').')',
            'opcache' => $yes($panel['opcache'] ?? false),
            'Config, routes, events cached' => $yes($panel['config_cached'] ?? false).', '.$yes($panel['routes_cached'] ?? false).', '.$yes($panel['events_cached'] ?? false),
            'Compiled views' => (string) ($panel['views_compiled'] ?? 0),
            'Debug, environment' => $yes($panel['debug'] ?? false).', '.($panel['environment'] ?? '?'),
            'Cache, session, queue, database' => implode(', ', [$panel['cache'] ?? '?', $panel['session'] ?? '?', $panel['queue'] ?? '?', $panel['database'] ?? '?']),
            'Extensions' => $extensions,
            'phpseclib math' => (string) ($panel['phpseclib_math'] ?? '?'),
        ];

        return "Panel (as the web server runs it)\n".collect($lines)->map(fn (string $value, string $label): string => sprintf("  %-34s %s\n", $label, $value))->implode('');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function cliSection(array $report): string
    {
        $cli = $report['cli'];
        $start = $cli['process_start'] ?? [];
        $output = sprintf("  mandal %s (build %s), %d vCPUs, load %s\n", $cli['version'] ?? '?', $cli['build'] ?? '?', $cli['vcpus'] ?? 0, $cli['load'] ?? '?');
        $output .= sprintf("  Starting a process: %.2f ms (%d in %.3f s)\n", $start['per_process_ms'] ?? 0, $start['count'] ?? 0, $start['seconds'] ?? 0);

        $output .= "\nInventory collectors, each on its own (slowest first)\n";
        $output .= $this->rows(collect($cli['collectors'] ?? [])->mapWithKeys(fn (array $row): array => [$row['name'] => (float) $row['seconds']])->all());

        $output .= "\nRead commands, each through a fresh CLI (slowest first)\n";
        $output .= $this->rows(collect($cli['commands'] ?? [])->mapWithKeys(fn (array $row): array => [
            $row['command'].($row['exit_code'] === 0 ? '' : " (exit {$row['exit_code']})") => (float) $row['seconds'],
        ])->all());

        $onServer = collect($cli['commands'] ?? [])->firstWhere('command', 'inventory')['seconds'] ?? null;

        if ($onServer !== null && isset($report['inventory']['fresh'])) {
            $output .= "\nInventory: ".sprintf('%.3f s through the panel, %.3f s on the server, so the panel side (SSH, transfer, JSON, cache) adds %.3f s', $report['inventory']['fresh'], $onServer, max(0, $report['inventory']['fresh'] - $onServer))."\n";
        }

        return $output;
    }

    /**
     * @param  array<string, float>  $rows
     */
    private function rows(array $rows): string
    {
        $width = max([34, ...array_map(strlen(...), array_keys($rows))]);

        return collect($rows)->map(fn (float $seconds, string $label): string => sprintf("  %-{$width}s %8.3f s\n", $label, $seconds))->implode('');
    }

    /**
     * The fastest of several runs, in seconds.
     */
    private function best(int $times, callable $callback): float
    {
        $best = INF;

        for ($i = 0; $i < $times; $i++) {
            $start = hrtime(true);
            $callback();
            $best = min($best, $this->since($start));
        }

        return $best;
    }

    private function since(int|float $start): float
    {
        return round((hrtime(true) - $start) / 1e9, 3);
    }
}
