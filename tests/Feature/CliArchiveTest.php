<?php

use App\Mandal\CliArchive;

it('builds an archive containing the tool and its version but not the tests or setup scripts', function () {
    $listing = shell_exec('tar -tzf '.escapeshellarg(app(CliArchive::class)->path()));

    expect($listing)->toContain('bin/mandal')
        ->toContain('lib/core.sh')
        ->toContain('lib/roles/database/mariadb.sh')
        ->toContain('VERSION')
        ->not->toContain('tests/')
        ->not->toContain('setup.sh')
        ->not->toContain('install.sh');
});

it('reports the version from the VERSION file', function () {
    expect(app(CliArchive::class)->version())->toMatch('/^\d+\.\d+\.\d+$/');
});

it('rebuilds the archive when a CLI file changes', function () {
    $source = sys_get_temp_dir().'/mandal-cli-source-'.uniqid();
    $cache = sys_get_temp_dir().'/mandal-cli-cache-'.uniqid();
    mkdir("{$source}/bin", 0755, true);
    file_put_contents("{$source}/bin/mandal", "#!/usr/bin/env bash\necho one\n");

    $archive = new CliArchive($source, $cache);
    $first = $archive->path();

    file_put_contents("{$source}/bin/mandal", "#!/usr/bin/env bash\necho two\n");
    $second = $archive->path();

    expect($second)->not->toBe($first)
        ->and(file_exists($first))->toBeFalse()
        ->and(file_exists($second))->toBeTrue();
});
