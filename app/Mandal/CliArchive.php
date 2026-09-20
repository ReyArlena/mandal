<?php

namespace App\Mandal;

use Phar;
use PharData;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Builds the tar.gz of the server CLI that a server downloads during setup
 * or an upgrade. Archives are cached per version.
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
     * The path of the archive for the current version, building it if needed.
     */
    public function path(): string
    {
        $version = $this->version();
        $archive = $this->cacheDirectory()."/mandal-cli-{$version}.tar.gz";

        if (is_file($archive)) {
            return $archive;
        }

        $this->build($archive);

        return $archive;
    }

    private function build(string $archive): void
    {
        $source = $this->sourcePath ?: base_path('server');

        if (! is_dir($source)) {
            throw new RuntimeException("The server CLI source directory is missing: {$source}");
        }

        if (! is_dir(dirname($archive)) && ! mkdir(dirname($archive), 0755, true) && ! is_dir(dirname($archive))) {
            throw new RuntimeException('Could not create the archive cache directory.');
        }

        $tar = substr($archive, 0, -3);
        @unlink($tar);
        @unlink($archive);

        $phar = new PharData($tar);

        foreach ((new Finder)->files()->in($source)->notPath('tests')->ignoreDotFiles(true) as $file) {
            $phar->addFile($file->getRealPath(), $file->getRelativePathname());
        }

        $phar->addFile(base_path('VERSION'), 'VERSION');
        $phar->compress(Phar::GZ);

        unset($phar);
        @unlink($tar);
    }

    private function cacheDirectory(): string
    {
        return $this->cachePath ?: storage_path('app/cli');
    }
}
