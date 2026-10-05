<?php

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerApp;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Jobs\RequestCertificate;
use App\Jobs\UpdateCli;
use App\Mandal\CliArchive;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Setting;
use App\Models\SourceCredential;
use App\Models\Task;
use App\Models\User;
use App\Models\Webhook;
use App\Ssh\SshClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email' => 'ops@example.com']));
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
    $this->server = Server::factory()->provisioned()->create();
    $this->ssh->respondWithJson('inventory', $this->inventory);
});

it('queues a certificate request with the email and DNS option', function () {
    Queue::fake();

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->callAction('requestCertificate', data: ['email' => 'ops@example.com', 'skip_dns_check' => true], arguments: ['name' => 'shop'])
        ->assertNotified('Requesting a certificate for shop');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::Certificate)
        ->and((new RequestCertificate($task))->arguments())->toBe("app:ssl --name=shop --email='ops@example.com' --skip-dns-check");

    Queue::assertPushed(RequestCertificate::class);
});

it('drops an invalid certificate email', function () {
    $task = Task::factory()->create(['options' => ['name' => 'shop', 'email' => 'not-an-email']]);

    expect((new RequestCertificate($task))->arguments())->toBe('app:ssl --name=shop');
});

it('prefills the env editor from the server and saves through an upload', function () {
    $this->ssh->respondTo('app:env --name=shop --from=', "Updated /home/shop/shared/.env.\n");
    $this->ssh->respondTo('app:env --name=shop', "APP_ENV=production\nAPP_DEBUG=false\n");
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->mountAction('editEnv', arguments: ['name' => 'shop'])
        ->assertActionDataSet(['contents' => "APP_ENV=production\nAPP_DEBUG=false\n"])
        ->setActionData(['contents' => "APP_ENV=production\nAPP_DEBUG=true"])
        ->callMountedAction()
        ->assertNotified('Saved shop .env');

    $upload = collect($this->ssh->uploads)->first();

    expect($upload['contents'])->toBe("APP_ENV=production\nAPP_DEBUG=true\n")
        ->and($upload['mode'])->toBe(0600)
        ->and(array_key_first($this->ssh->uploads))->toStartWith('.mandal/')
        ->and(array_key_first($this->ssh->uploads))->toEndWith('.env')
        ->and($this->ssh->ran("app:env --name=shop --from='/home/mandal/.mandal/"))->toBeTrue();
});

it('reports an env save failure', function () {
    $this->ssh->respondTo('app:env --name=shop --from=', '', exitCode: 2, errorOutput: 'error: the new .env contains binary data');
    $this->ssh->respondTo('app:env --name=shop', "A=1\n");
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->mountAction('editEnv', arguments: ['name' => 'shop'])
        ->setActionData(['contents' => 'A=2'])
        ->callMountedAction()
        ->assertNotified('Could not save .env');
});

it('offers a CLI update only when the server build differs', function () {
    $hash = app(CliArchive::class)->hash();

    livewire(ViewServer::class, ['record' => $this->server->getRouteKey()])
        ->assertActionVisible('updateCli');

    $current = $this->inventory;
    $current['mandal']['build'] = $hash;
    $this->ssh->respondWithJson('inventory', $current);
    app(MandalClient::class)->forgetInventory($this->server);

    livewire(ViewServer::class, ['record' => $this->server->getRouteKey()])
        ->assertActionHidden('updateCli');
});

it('queues a CLI update', function () {
    Queue::fake();

    livewire(ViewServer::class, ['record' => $this->server->getRouteKey()])
        ->callAction('updateCli')
        ->assertNotified('CLI update started');

    expect($this->server->tasks()->sole()->type)->toBe(TaskType::UpdateCli);
    Queue::assertPushed(UpdateCli::class);
});

it('uploads the archive and installs it', function () {
    $task = Task::factory()->for($this->server)->create(['type' => TaskType::UpdateCli]);
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');
    $this->ssh->respondTo('cli:update --from=', "==> Installing the CLI\nInstalled mandal 0.1.0 (build abc).\n");

    (new UpdateCli($task))->handle(new MandalClient($this->ssh));

    expect($task->fresh()->output)->toContain('Installed mandal')
        ->and(array_key_first($this->ssh->uploads))->toEndWith('.tar.gz')
        ->and(strlen(collect($this->ssh->uploads)->first()['contents']))->toBeGreaterThan(1000)
        ->and($this->ssh->ran("sudo -n /opt/mandal/bin/mandal cli:update --from='/home/mandal/.mandal/"))->toBeTrue();
});

it('enables and disables auto deploy from the app page', function () {
    Setting::set(Setting::PUBLIC_URL, 'https://panel.example.com');
    SourceCredential::factory()->create();
    Http::fake([
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 5], 201),
        'api.github.com/repos/acme/shop/hooks/5/pings' => function () {
            Webhook::query()->firstOrFail()->recordDelivery('ping');

            return Http::response(null, 204);
        },
        'api.github.com/repos/acme/shop/hooks/5/deliveries?per_page=1' => Http::response([['id' => 1, 'status' => 'OK', 'status_code' => 200, 'event' => 'ping', 'delivered_at' => 'now']]),
        'api.github.com/repos/acme/shop/hooks/5' => Http::response(null, 204),
    ]);

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->assertActionVisible('enableAutoDeploy')
        ->assertActionHidden('disableAutoDeploy')
        ->callAction('enableAutoDeploy')
        ->assertNotified('Auto deploy enabled for shop')
        ->assertSee('last delivery ping')
        ->assertActionVisible('disableAutoDeploy')
        ->callAction('disableAutoDeploy')
        ->assertNotified('Auto deploy disabled for shop');

    expect(Webhook::query()->count())->toBe(0);
});

it('explains why auto deploy stays off', function () {
    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->callAction('enableAutoDeploy')
        ->assertNotified('Auto deploy stays off');
});
