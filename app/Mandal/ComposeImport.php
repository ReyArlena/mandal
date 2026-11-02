<?php

namespace App\Mandal;

use App\Models\Server;
use App\Ssh\SshException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * A backup of a Docker project folder, as a .zip or .tar.gz, to start a new
 * Docker app from. Reading it checks every entry (plain files and folders,
 * paths that stay inside the folder, a size cap) and finds the compose file
 * at the top, or inside a single top-level folder. The compose file and .env
 * fill the form; the rest is repacked as a .tar.gz for `app:create --files`.
 */
final class ComposeImport
{
    /**
     * The names Compose looks for, in its own order. Whichever is found is
     * saved as compose.yml.
     */
    public const array COMPOSE_NAMES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    private const int MAX_ENTRIES = 200000;

    private const int MAX_TEXT = 262144;

    /**
     * @param  'zip'|'tar.gz'  $format
     * @param  array<string, string>  $members  normalised path => name in the archive
     */
    private function __construct(
        public readonly string $path,
        public readonly string $format,
        public readonly string $prefix,
        public readonly string $composeName,
        public readonly string $compose,
        public readonly ?string $env,
        public readonly int $files,
        public readonly int $bytes,
        private readonly array $members,
    ) {}

    /**
     * @throws InvalidImport with a message for the user
     */
    public static function inspect(string $path): self
    {
        $format = self::format($path);
        $entries = $format === 'zip' ? self::zipEntries($path) : self::tarEntries($path);

        if (count($entries) > self::MAX_ENTRIES) {
            throw new InvalidImport('The archive holds more than '.number_format(self::MAX_ENTRIES).' entries.');
        }

        $members = [];
        $bytes = 0;
        $files = 0;

        foreach ($entries as [$name, $type, $size]) {
            $normalised = self::normalise($name);

            // macOS adds these to every zip it makes; they are not the project's.
            if ($normalised === '' || $normalised === '__MACOSX' || str_starts_with($normalised, '__MACOSX/') || basename($normalised) === '.DS_Store') {
                continue;
            }

            if ($type === 'link') {
                throw new InvalidImport("The archive holds a link ({$normalised}); only files and folders can be imported.");
            }

            if ($type === 'other') {
                throw new InvalidImport("The archive holds something that is not a file or folder ({$normalised}).");
            }

            $members[$normalised.($type === 'dir' ? '/' : '')] = $name;

            if ($type === 'file') {
                $files++;
                $bytes += $size;
            }
        }

        $limit = self::limit('max_unpacked', '20G');

        if ($bytes > $limit) {
            throw new InvalidImport('The archive unpacks to '.self::human($bytes).', more than the '.self::human($limit).' allowed.');
        }

        $prefix = self::prefix(array_keys($members));
        $composeName = collect(self::COMPOSE_NAMES)->first(fn (string $name): bool => isset($members[$prefix.$name]));

        if ($composeName === null) {
            throw new InvalidImport('No compose file at the top of the archive (looked for '.implode(', ', self::COMPOSE_NAMES).').');
        }

        $compose = self::read($path, $format, $members[$prefix.$composeName]);

        if (trim($compose) === '' || strlen($compose) > self::MAX_TEXT || str_contains($compose, "\0")) {
            throw new InvalidImport("{$composeName} is empty, larger than 256 KB or not text.");
        }

        $env = isset($members[$prefix.'.env']) ? self::read($path, $format, $members[$prefix.'.env']) : null;

        if ($env !== null && (strlen($env) > self::MAX_TEXT || str_contains($env, "\0"))) {
            throw new InvalidImport('.env is larger than 256 KB or not text.');
        }

        return new self($path, $format, $prefix, $composeName, $compose, $env, $files, $bytes, $members);
    }

    /**
     * The .env lines as the form's environment field takes them: KEY=value,
     * without comments or blank lines.
     */
    public function envLines(): string
    {
        return collect(preg_split('/\R/', (string) $this->env))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => (bool) preg_match('/^[A-Z][A-Z0-9_]*=/', $line))
            ->implode("\n");
    }

    /**
     * Writes the archive's files, without the compose file and .env (the
     * form supplies those), as a .tar.gz with paths relative to the project
     * folder. Returns its size in bytes.
     */
    public function repack(string $target): int
    {
        $work = storage_path('app/private/imports/work-'.Str::random(16));
        File::ensureDirectoryExists($work, 0700);

        try {
            if ($this->format === 'zip') {
                $zip = new ZipArchive;

                if ($zip->open($this->path) !== true || ! $zip->extractTo($work, array_values($this->members))) {
                    throw new InvalidImport('The zip file could not be unpacked.');
                }

                $zip->close();
            } else {
                self::process(['tar', '-xzf', $this->path, '-C', $work, '--no-same-owner'], 'The archive could not be unpacked.');
            }

            $base = $work.'/'.rtrim($this->prefix, '/');

            foreach ([...self::COMPOSE_NAMES, '.env'] as $name) {
                File::delete($base.'/'.$name);
            }

            File::ensureDirectoryExists(dirname($target), 0700);
            self::process(['tar', '-czf', $target, '-C', $base, '.'], 'The files could not be packed for the server.');

            return (int) filesize($target);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Addresses the panel uses for the server it runs on. */
    private const array PANEL_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /**
     * What the panel accepts in one upload: the smallest of PHP's two limits,
     * nginx's limit on the server the panel runs on and the configured import
     * limit.
     */
    public static function maxUploadBytes(): int
    {
        return min(
            self::bytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX,
            self::bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX,
            self::panelNginxBytes() ?: PHP_INT_MAX,
            self::configuredUploadBytes(),
        );
    }

    /**
     * nginx's client_max_body_size on the server the panel runs on, when the
     * panel manages that server; 0 when it is unknown or unlimited. A larger
     * upload gets a 413 from nginx before PHP sees it.
     */
    private static function panelNginxBytes(): int
    {
        $server = Server::query()->whereIn('host', self::PANEL_HOSTS)->first();

        if ($server === null || ! $server->isProvisioned()) {
            return 0;
        }

        try {
            $size = app(MandalClient::class)->inventory($server)['nginx']['client_max_body_size']['value'] ?? null;
        } catch (SshException) {
            return 0;
        }

        return is_string($size) ? self::bytes($size) : 0;
    }

    /**
     * The panel's own import limit, before PHP's limits are applied.
     */
    public static function configuredUploadBytes(): int
    {
        return self::limit('max_upload', '1G');
    }

    /**
     * A size from config/mandal.php, or the default when it is missing or
     * unreadable: a worker started before the setting existed sees none,
     * which must not read as "nothing allowed".
     */
    private static function limit(string $key, string $default): int
    {
        $bytes = self::bytes((string) config("mandal.imports.{$key}", $default));

        return $bytes > 0 ? $bytes : self::bytes($default);
    }

    /**
     * A size such as 1G, 512M or 8K (PHP and nginx notation) in bytes.
     */
    public static function bytes(string $size): int
    {
        $size = trim($size);
        $number = (int) $size;

        return match (strtoupper(substr($size, -1))) {
            'K' => $number * 1024,
            'M' => $number * 1024 ** 2,
            'G' => $number * 1024 ** 3,
            default => $number,
        };
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? "{$bytes} B" : rtrim(rtrim(number_format($value, 1), '0'), '.').' '.$units[$unit];
    }

    /**
     * @return 'zip'|'tar.gz'
     */
    private static function format(string $path): string
    {
        $magic = (string) @file_get_contents($path, length: 4);

        return match (true) {
            str_starts_with($magic, "PK\x03\x04"), str_starts_with($magic, "PK\x05\x06") => 'zip',
            str_starts_with($magic, "\x1f\x8b") => 'tar.gz',
            default => throw new InvalidImport('The file is not a .zip or .tar.gz archive.'),
        };
    }

    /**
     * @return list<array{string, 'file'|'dir'|'link'|'other', int}>
     */
    private static function zipEntries(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidImport('The zip file is damaged or not a zip file.');
        }

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $mode = $system === ZipArchive::OPSYS_UNIX ? ($attributes >> 16) & 0170000 : 0;
            $name = (string) $stat['name'];

            $type = match (true) {
                $mode === 0120000 => 'link',
                str_ends_with($name, '/') || $mode === 0040000 => 'dir',
                $mode === 0 || $mode === 0100000 => 'file',
                default => 'other',
            };

            $entries[] = [$name, $type, (int) $stat['size']];
        }

        $zip->close();

        return $entries;
    }

    /**
     * Entries of a .tar.gz from GNU tar's listing: the names from -t and the
     * type and size from -tv, line for line.
     *
     * @return list<array{string, 'file'|'dir'|'link'|'other', int}>
     */
    private static function tarEntries(string $path): array
    {
        $names = self::lines(self::process(['tar', '-tzf', $path], 'The archive is damaged or not a .tar.gz.'));
        $details = self::lines(self::process(['tar', '-tzvf', $path], 'The archive is damaged or not a .tar.gz.'));

        if (count($names) !== count($details)) {
            throw new InvalidImport('The archive has names this panel cannot read.');
        }

        $entries = [];

        foreach ($names as $index => $name) {
            $columns = preg_split('/\s+/', $details[$index]) ?: [];

            $type = match ($details[$index][0] ?? '') {
                '-' => 'file',
                'd' => 'dir',
                'l', 'h' => 'link',
                default => 'other',
            };

            $entries[] = [$name, $type, (int) ($columns[2] ?? 0)];
        }

        return $entries;
    }

    /**
     * A member's path without a leading ./ or trailing /, refused when it
     * leaves the folder or uses characters a file name should not have.
     */
    private static function normalise(string $name): string
    {
        $path = trim(preg_replace('#^(\./)+#', '', $name) ?? '', '/');

        if ($path === '' || $path === '.') {
            return '';
        }

        if (str_starts_with($name, '/') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) {
            throw new InvalidImport("The archive holds a path that cannot be imported ({$name}).");
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new InvalidImport("The archive holds a path outside its folder ({$name}).");
            }
        }

        return $path;
    }

    /**
     * The single top-level folder everything sits in (as "folder/"), when
     * the compose file is not at the top itself.
     *
     * @param  list<string>  $paths
     */
    private static function prefix(array $paths): string
    {
        foreach (self::COMPOSE_NAMES as $name) {
            if (in_array($name, $paths, true)) {
                return '';
            }
        }

        $tops = array_values(array_unique(array_map(fn (string $path): string => explode('/', $path)[0], $paths)));

        if (count($tops) !== 1) {
            return '';
        }

        $folder = $tops[0].'/';

        return collect($paths)->contains(fn (string $path): bool => str_starts_with($path, $folder)) ? $folder : '';
    }

    private static function read(string $path, string $format, string $member): string
    {
        if ($format === 'zip') {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::RDONLY);
            $contents = $zip->getFromName($member);
            $zip->close();

            return is_string($contents) ? $contents : throw new InvalidImport("{$member} could not be read from the archive.");
        }

        return self::process(['tar', '-xzOf', $path, $member], "{$member} could not be read from the archive.");
    }

    /**
     * @param  list<string>  $command
     */
    private static function process(array $command, string $error): string
    {
        $process = new Process($command);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new InvalidImport($error);
        }

        return $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private static function lines(string $output): array
    {
        return array_values(array_filter(explode("\n", $output), fn (string $line): bool => $line !== ''));
    }
}
