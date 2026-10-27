<?php

namespace App\Backups;

use App\Models\BackupDestination;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The form fields, defaults and conf lines shared by the database backup
 * settings and each app's backup settings: an enable switch, a local
 * destination and a remote one, each with its own schedule and retention.
 */
final class BackupTargetForm
{
    public const string CRON_PATTERN = '/^([0-9*\/,-]+\s+){4}[0-9*\/,A-Za-z-]+$/';

    public const string SUBFOLDER_PATTERN = '#^[A-Za-z0-9._/-]{0,200}$#';

    public const string DEFAULT_SCHEDULE = '30 2 * * *';

    /**
     * Frequency presets; anything else is shown as a custom cron line.
     *
     * @var array<string, array{label: string, cron: string, short: string}>
     */
    public const array FREQUENCIES = [
        'hourly' => ['label' => 'Hourly', 'cron' => '0 * * * *', 'short' => 'hourly'],
        'six-hours' => ['label' => 'Every 6 hours', 'cron' => '0 */6 * * *', 'short' => 'every 6 hours'],
        'daily' => ['label' => 'Daily at 02:30', 'cron' => '30 2 * * *', 'short' => 'daily'],
        'weekly' => ['label' => 'Weekly on Sunday at 03:00', 'cron' => '0 3 * * 0', 'short' => 'weekly'],
    ];

    /**
     * Form state from the status (databases) or config (apps) object the
     * CLI reports: {enabled, local: {enabled, schedule, keep}, remote: {name,
     * schedule, keep, path} | null}.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function fill(array $config): array
    {
        $local = is_array($config['local'] ?? null) ? $config['local'] : [];
        $remote = is_array($config['remote'] ?? null) ? $config['remote'] : [];

        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'local' => (bool) ($local['enabled'] ?? true),
            ...self::frequencyState('local', (string) ($local['schedule'] ?? '') ?: self::DEFAULT_SCHEDULE),
            'local_keep' => $local['keep'] ?? 7,
            'remote' => ($remote['name'] ?? '') ?: null,
            'remote_path' => $remote['path'] ?? '',
            ...self::frequencyState('remote', (string) ($remote['schedule'] ?? '') ?: self::DEFAULT_SCHEDULE),
            'remote_keep' => $remote['keep'] ?? 30,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function frequencyState(string $prefix, string $cron): array
    {
        foreach (self::FREQUENCIES as $key => $frequency) {
            if ($frequency['cron'] === $cron) {
                return ["{$prefix}_frequency" => $key, "{$prefix}_cron" => ''];
            }
        }

        return ["{$prefix}_frequency" => 'custom', "{$prefix}_cron" => $cron];
    }

    public static function enabledToggle(): Toggle
    {
        return Toggle::make('enabled')
            ->label('Enable backups')
            ->helperText('When off, nothing runs on a schedule. Back up now still works.')
            ->default(true);
    }

    public static function localSection(): Section
    {
        return Section::make('Local')
            ->description('Kept under /var/backups/mandal on the server.')
            ->schema([
                Toggle::make('local')->label('Keep copies on the server')->default(true)->live(),
                ...self::scheduleFields('local', 7, fn (Get $get): bool => (bool) $get('local')),
            ])
            ->columns(3)
            ->compact();
    }

    /**
     * @param  string  $leaf  the last path segment shown in the helper text, such as `<database>` or the app name
     */
    public static function remoteSection(string $leaf): Section
    {
        $chosen = fn (Get $get): bool => filled($get('remote'));

        return Section::make('Remote')
            ->description('Uploaded to one of the destinations from Settings.')
            ->schema([
                Select::make('remote')
                    ->label('Destination')
                    ->options(fn (): array => BackupDestination::query()->orderBy('name')->pluck('name', 'slug')->all())
                    ->placeholder('None')
                    ->native(false)
                    ->live()
                    ->columnSpan(2),
                TextInput::make('remote_path')
                    ->label('Subfolder')
                    ->regex(self::SUBFOLDER_PATTERN)
                    ->placeholder('server-1')
                    ->live(onBlur: true)
                    ->visible($chosen)
                    ->helperText(function (Get $get) use ($leaf): ?string {
                        $destination = BackupDestination::query()->where('slug', (string) $get('remote'))->first();

                        return $destination === null ? null : 'Files go to '.$destination->remotePath((string) $get('remote_path'), $leaf);
                    }),
                ...self::scheduleFields('remote', 30, $chosen),
            ])
            ->columns(3)
            ->compact();
    }

    /**
     * Frequency preset, custom cron and retention for one destination.
     *
     * @param  Closure(Get): bool  $visible
     * @return list<Select|TextInput>
     */
    private static function scheduleFields(string $prefix, int $defaultKeep, Closure $visible): array
    {
        return [
            Select::make("{$prefix}_frequency")
                ->label('Frequency')
                ->options([
                    ...array_map(fn (array $frequency): string => $frequency['label'], self::FREQUENCIES),
                    'custom' => 'Custom',
                ])
                ->default('daily')
                ->required()
                ->native(false)
                ->live()
                ->visible($visible),
            TextInput::make("{$prefix}_cron")
                ->label('Cron')
                ->regex(self::CRON_PATTERN)
                ->placeholder(self::DEFAULT_SCHEDULE)
                ->helperText('Five cron fields, server time is UTC.')
                ->visible(fn (Get $get): bool => $visible($get) && $get("{$prefix}_frequency") === 'custom')
                ->required(fn (Get $get): bool => $get("{$prefix}_frequency") === 'custom'),
            TextInput::make("{$prefix}_keep")
                ->label('Copies to keep')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(999)
                ->default($defaultKeep)
                ->required()
                ->visible($visible),
        ];
    }

    /**
     * The target conf lines for the configure commands. Hidden fields are
     * not submitted, so a destination that is off keeps sensible values.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function lines(array $data): array
    {
        $remote = (string) ($data['remote'] ?? '');

        return [
            'ENABLED='.(! empty($data['enabled']) ? 'true' : 'false'),
            'LOCAL='.(! empty($data['local']) ? 'true' : 'false'),
            'LOCAL_SCHEDULE='.self::schedule($data, 'local'),
            'LOCAL_KEEP='.(int) ($data['local_keep'] ?? 7),
            'REMOTE='.$remote,
            'REMOTE_SCHEDULE='.self::schedule($data, 'remote'),
            'REMOTE_KEEP='.(int) ($data['remote_keep'] ?? 30),
            'REMOTE_PATH='.($remote === '' ? '' : trim((string) ($data['remote_path'] ?? ''), '/')),
        ];
    }

    /**
     * The destination slug a set of conf lines refers to, if any.
     *
     * @param  list<string>  $lines
     */
    public static function remoteSlug(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, 'REMOTE=')) {
                $slug = substr($line, strlen('REMOTE='));

                return $slug === '' ? null : $slug;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function schedule(array $data, string $prefix): string
    {
        $frequency = (string) ($data["{$prefix}_frequency"] ?? 'daily');

        if ($frequency === 'custom') {
            $cron = trim((string) ($data["{$prefix}_cron"] ?? ''));

            return preg_match(self::CRON_PATTERN, $cron) ? $cron : self::DEFAULT_SCHEDULE;
        }

        return self::FREQUENCIES[$frequency]['cron'] ?? self::DEFAULT_SCHEDULE;
    }

    /**
     * "daily", "every 6 hours" or the cron line itself.
     */
    public static function describeSchedule(?string $cron): string
    {
        foreach (self::FREQUENCIES as $frequency) {
            if ($frequency['cron'] === $cron) {
                return $frequency['short'];
            }
        }

        return (string) $cron;
    }

    /**
     * One line for a card header: "Local: daily, keeps 7 · Remote: rustfs,
     * weekly, keeps 30".
     *
     * @param  array<string, mixed>  $config
     */
    public static function summary(array $config): string
    {
        $parts = [];
        $local = is_array($config['local'] ?? null) ? $config['local'] : [];
        $remote = is_array($config['remote'] ?? null) ? $config['remote'] : null;

        if ($local['enabled'] ?? false) {
            $parts[] = sprintf('Local: %s, keeps %d', self::describeSchedule($local['schedule'] ?? null), (int) ($local['keep'] ?? 0));
        }

        if ($remote !== null && ($remote['name'] ?? '') !== '') {
            $name = BackupDestination::query()->where('slug', $remote['name'])->value('name') ?? $remote['name'];
            $parts[] = sprintf('Remote: %s, %s, keeps %d', $name, self::describeSchedule($remote['schedule'] ?? null), (int) ($remote['keep'] ?? 0));
        }

        return $parts === [] ? 'No destination' : implode(' · ', $parts);
    }
}
