<?php

namespace App\Mandal;

use Phar;
use PharData;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Builds the tar.gz of the server CLI that a server downloads during setup
 * or an upgrade. Archives are cached by version and content hash, so a
 * change to any CLI file produces a new archive.
 */
class CliArchive
{
    public function __construct(
        private readonly string $sourcePath = '',
        private readonly string $cachePath = '',
    ) {}

    public function version(): string
    {
        $file = base_path('VERSION');

        if (! is_readable($file)) {
            throw new RuntimeException('The VERSION file is missing.');
        }

        return trim((string) file_get_contents($file));
    }

    /**
     * A short hash of every file that goes into the archive.
     */
    public function hash(): string
    {
        $context = hash_init('sha256');

        foreach ($this->files() as $file) {
            hash_update($context, $file->getRelativePathname());
            hash_update($context, (string) file_get_contents($file->getRealPath()));
        }

        hash_update($context, $this->version());

        return substr(hash_final($context), 0, 12);
    }

    /**
     * The path of the archive for the current source, building it if needed.
     */
    public function path(): string
    {
        $archive = $this->cacheDirectory()."/mandal-cli-{$this->version()}-{$this->hash()}.tar.gz";

        if (is_file($archive)) {
            return $archive;
        }

        $this->build($archive);

        return $archive;
    }

    private function build(string $archive): void
    {
        if (! is_dir(dirname($archive)) && ! mkdir(dirname($archive), 0755, true) && ! is_dir(dirname($archive))) {
            throw new RuntimeException('Could not create the archive cache directory.');
        }

        $tar = substr($archive, 0, -3);
        @unlink($tar);
        @unlink($archive);

        $phar = new PharData($tar);

        foreach ($this->files() as $file) {
            $phar->addFile($file->getRealPath(), $file->getRelativePathname());
        }

        $phar->addFile(base_path('VERSION'), 'VERSION');
        $phar->compress(Phar::GZ);

        unset($phar);
        @unlink($tar);

        foreach (glob($this->cacheDirectory().'/mandal-cli-*.tar.gz') ?: [] as $old) {
            if ($old !== $archive) {
                @unlink($old);
            }
        }
    }

    private function files(): Finder
    {
        $source = $this->sourcePath ?: base_path('server');

        if (! is_dir($source)) {
            throw new RuntimeException("The server CLI source directory is missing: {$source}");
        }

        // Only bin/ and lib/ ship: the scripts at the top of server/ are
        // fetched from GitHub releases, and the CLI hashes bin/ and lib/ to
        // compare its build with this archive.
        return (new Finder)->files()->in($source)->depth('>= 1')->notPath('tests')->ignoreDotFiles(true)->sortByName();
    }

    private function cacheDirectory(): string
    {
        return $this->cachePath ?: storage_path('app/cli');
    }
}
