<?php

namespace App\Filament\Resources\Servers\Concerns;

use App\Enums\TaskType;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\DeleteApp;
use App\Jobs\DeployApp;
use App\Jobs\RequestCertificate;
use App\Jobs\RollbackApp;
use App\Jobs\RunMandalTask;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Sources\AutoDeploy;
use App\Ssh\CommandResult;
use App\Ssh\SshException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * The actions that act on one app of a server. Each takes the app name as
 * an action argument so the same definitions serve the list and the
 * detail page.
 */
trait ManagesApps
{
    public const string APP_NAME_PATTERN = '/^[a-z][a-z0-9-]{1,31}$/';

    public const string WORKER_NAME_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/';

    public const string WORKER_COMMAND_PATTERN = '/^[A-Za-z0-9:_=,. -]{1,200}$/';

    abstract protected function server(): Server;

    /**
     * Called after a synchronous change so the page can reload its data.
     */
    protected function appChanged(): void {}

    public function deployAppAction(): Action
    {
        return Action::make('deployApp')
            ->label('Deploy')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Deploy {$arguments['name']}?")
            ->modalDescription('Clones the configured branch into a new release, runs the app\'s deploy script and switches over when it succeeds. The live release is untouched if anything fails.')
            ->modalSubmitActionLabel('Deploy')
            ->action(function (array $arguments): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $this->queueTask(TaskType::Deploy, DeployApp::class, ['name' => $name], "Deploying {$name}");
            });
    }

    public function rollbackAppAction(): Action
    {
        return Action::make('rollbackApp')
            ->label('Roll back')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => isset($arguments['release'])
                ? "Roll back {$arguments['name']} to release {$arguments['release']}?"
                : "Roll back {$arguments['name']}?")
            ->modalDescription('Switches to the chosen release and restarts the workers. Database migrations are not reversed.')
            ->modalSubmitActionLabel('Roll back')
            ->action(function (array $arguments): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $release = (string) ($arguments['release'] ?? '');

                $this->queueTask(TaskType::Rollback, RollbackApp::class, [
                    'name' => $name,
                    'release' => preg_match('/^\d{14}$/', $release) ? $release : null,
                ], "Rolling back {$name}");
            });
    }

    public function requestCertificateAction(): Action
    {
        return Action::make('requestCertificate')
            ->label('SSL')
            ->icon(Heroicon::OutlinedLockClosed)
            ->modalHeading(fn (array $arguments): string => "Request a certificate for {$arguments['name']}")
            ->modalDescription('Checks that every host name points at this server, then asks Let\'s Encrypt for a certificate and switches the site to HTTPS. Renewals are automatic.')
            ->modalSubmitActionLabel('Request certificate')
            ->schema([
                TextInput::make('email')
                    ->label('Email for expiry notices')
                    ->email()
                    ->default(fn (): ?string => auth()->user()?->email),
                Checkbox::make('skip_dns_check')
                    ->label('Skip the DNS check (server behind NAT)'),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $this->queueTask(TaskType::Certificate, RequestCertificate::class, [
                    'name' => $name,
                    'email' => $data['email'] ?: null,
                    'skip_dns_check' => (bool) ($data['skip_dns_check'] ?? false),
                ], "Requesting a certificate for {$name}");
            });
    }

    public function editComposeAction(): Action
    {
        return Action::make('editCompose')
            ->label('Compose file')
            ->icon(Heroicon::OutlinedCube)
            ->modalHeading(fn (array $arguments): string => "{$arguments['name']} compose.yml")
            ->modalDescription('Checked with `docker compose config` and applied with `docker compose up -d`, which recreates the containers that changed. An invalid file is refused and the current one kept. Ports published here bypass the firewall.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Save and apply')
            ->fillForm(function (array $arguments): array {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return ['contents' => ''];
                }

                try {
                    $result = app(MandalClient::class)->run($this->server(), 'app:compose --name='.$name, timeoutSeconds: 30);
                } catch (SshException $exception) {
                    return ['contents' => '# Could not read the file: '.$exception->getMessage()];
                }

                return ['contents' => $result->successful() ? $result->output : '# '.trim($result->errorOutput ?: $result->output)];
            })
            ->schema([
                Textarea::make('contents')
                    ->hiddenLabel()
                    ->rows(24)
                    ->required()
                    ->maxLength(262144)
                    ->extraInputAttributes(['class' => 'font-mono text-sm', 'spellcheck' => 'false']),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal) => $mandal->runWithUpload($this->server(), 'app:compose --name='.$name, rtrim((string) $data['contents'])."\n", '.yml', timeoutSeconds: 300),
                    "Applied {$name} compose.yml",
                    'Could not apply the compose file',
                );
            });
    }

    public function editEnvAction(): Action
    {
        return Action::make('editEnv')
            ->label('.env')
            ->icon(Heroicon::OutlinedDocumentText)
            ->modalHeading(fn (array $arguments): string => "{$arguments['name']} .env")
            ->modalDescription('Saved to shared/.env on the server. Running queue workers keep the old values until they are restarted or the app is redeployed.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (array $arguments): array {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return ['contents' => ''];
                }

                try {
                    $result = app(MandalClient::class)->run($this->server(), 'app:env --name='.$name, timeoutSeconds: 30);
                } catch (SshException $exception) {
                    return ['contents' => '# Could not read the file: '.$exception->getMessage()];
                }

                return ['contents' => $result->successful() ? $result->output : '# '.trim($result->errorOutput ?: $result->output)];
            })
            ->schema([
                Textarea::make('contents')
                    ->hiddenLabel()
                    ->rows(24)
                    ->required()
                    ->maxLength(262144)
                    ->extraInputAttributes(['class' => 'font-mono text-sm', 'spellcheck' => 'false']),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal) => $mandal->runWithUpload($this->server(), 'app:env --name='.$name, rtrim((string) $data['contents'])."\n", '.env', timeoutSeconds: 30),
                    "Saved {$name} .env",
                    'Could not save .env',
                );
            });
    }

    public function enableAutoDeployAction(): Action
    {
        return Action::make('enableAutoDeploy')
            ->label('Enable auto deploy')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Deploy {$arguments['name']} on every push?")
            ->modalDescription('Adds a webhook to the GitHub repository and checks that GitHub can reach this panel. Pushes to the app\'s branch then queue a deploy automatically.')
            ->modalSubmitActionLabel('Enable')
            ->action(function (array $arguments, AutoDeploy $autoDeploy): void {
                $name = $this->appName($arguments);
                $repo = (string) ($arguments['repo'] ?? '');

                if ($name === null || ! preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$#', $repo)) {
                    return;
                }

                try {
                    $autoDeploy->enable($this->server(), $name, $repo);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Auto deploy stays off')->body($exception->getMessage())->warning()->persistent()->send();

                    return;
                }

                Notification::make()->title("Auto deploy enabled for {$name}")->success()->send();
                $this->appChanged();
            });
    }

    public function disableAutoDeployAction(): Action
    {
        return Action::make('disableAutoDeploy')
            ->label('Disable auto deploy')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Stop deploying {$arguments['name']} on push?")
            ->modalDescription('Removes the webhook from GitHub. Manual deploys keep working.')
            ->action(function (array $arguments, AutoDeploy $autoDeploy): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $autoDeploy->disable($this->server(), $name);
                Notification::make()->title("Auto deploy disabled for {$name}")->success()->send();
                $this->appChanged();
            });
    }

    public function addWorkerAction(): Action
    {
        return Action::make('addWorker')
            ->label('Add worker')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(fn (array $arguments): string => "Add a worker to {$arguments['name']}")
            ->modalDescription('A Supervisor program running an artisan command as the app user. It restarts automatically and is restarted after every deploy.')
            ->modalSubmitActionLabel('Add worker')
            ->schema([
                TextInput::make('worker')
                    ->label('Name')
                    ->default('default')
                    ->required()
                    ->regex(self::WORKER_NAME_PATTERN),
                TextInput::make('command')
                    ->label('Artisan command')
                    ->default('queue:work --sleep=3 --tries=3 --max-time=3600')
                    ->required()
                    ->regex(self::WORKER_COMMAND_PATTERN)
                    ->helperText('Without "php artisan". Use "horizon" for Horizon or "reverb:start" for Reverb.'),
                TextInput::make('processes')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99)
                    ->default(1)
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $command = sprintf(
                    'worker:add --app=%s --name=%s --command=%s --processes=%d',
                    $name,
                    $data['worker'],
                    escapeshellarg((string) $data['command']),
                    (int) $data['processes'],
                );

                $this->runSynchronously(
                    fn (MandalClient $mandal) => $mandal->run($this->server(), $command, timeoutSeconds: 60),
                    "Worker {$data['worker']} added",
                    'Could not add the worker',
                );
            });
    }

    public function removeWorkerAction(): Action
    {
        return Action::make('removeWorker')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Remove worker {$arguments['worker']}?")
            ->action(function (array $arguments): void {
                $name = $this->appName($arguments);
                $worker = (string) ($arguments['worker'] ?? '');

                if ($name === null || ! preg_match(self::WORKER_NAME_PATTERN, $worker)) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal) => $mandal->run($this->server(), "worker:remove --app={$name} --name={$worker}", timeoutSeconds: 60),
                    "Worker {$worker} removed",
                    'Could not remove the worker',
                );
            });
    }

    public function restartWorkersAction(): Action
    {
        return Action::make('restartWorkers')
            ->label('Restart workers')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (array $arguments): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal) => $mandal->run($this->server(), "worker:restart --app={$name}", timeoutSeconds: 120),
                    'Workers restarted',
                    'Could not restart the workers',
                );
            });
    }

    public function deleteAppAction(): Action
    {
        return Action::make('deleteApp')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete {$arguments['name']}?")
            ->modalDescription('Removes the site, pool, worker programs, crontab and system user. Choose what to keep below.')
            ->schema([
                Checkbox::make('keep_database')->label('Keep the database and its user'),
                Checkbox::make('keep_files')->label('Keep the home directory and files'),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $this->queueTask(TaskType::DeleteApp, DeleteApp::class, [
                    'name' => $name,
                    'keep_database' => (bool) ($data['keep_database'] ?? false),
                    'keep_files' => (bool) ($data['keep_files'] ?? false),
                ], "Deleting {$name}");
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function appName(array $arguments): ?string
    {
        $name = (string) ($arguments['name'] ?? '');

        return preg_match(self::APP_NAME_PATTERN, $name) ? $name : null;
    }

    /**
     * @param  class-string<RunMandalTask>  $job
     * @param  array<string, mixed>  $options
     */
    protected function queueTask(TaskType $type, string $job, array $options, string $title): void
    {
        $server = $this->server();

        if ($server->hasActiveTask()) {
            Notification::make()
                ->title('Another task is still running')
                ->body('Wait for it to finish; only one task runs on a server at a time.')
                ->warning()
                ->send();

            return;
        }

        /** @var Task $task */
        $task = $server->tasks()->create([
            'type' => $type,
            'user_id' => auth()->id(),
            'options' => $options,
        ]);

        $job::dispatch($task);

        Notification::make()->title($title)->body('Follow the progress on the task page.')->success()->send();

        $this->redirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $task]));
    }

    /**
     * Run a quick command now and report the outcome.
     *
     * @param  callable(MandalClient): CommandResult  $command
     */
    protected function runSynchronously(callable $command, string $successTitle, string $failureTitle): void
    {
        $mandal = app(MandalClient::class);

        try {
            $result = $command($mandal);
        } catch (SshException $exception) {
            Notification::make()->title($failureTitle)->body($exception->getMessage())->danger()->send();

            return;
        }

        if ($result->failed()) {
            Notification::make()->title($failureTitle)->body(trim($result->errorOutput ?: $result->output))->danger()->send();

            return;
        }

        $mandal->forgetInventory($this->server());

        Notification::make()->title($successTitle)->success()->send();

        $this->appChanged();
    }
}
