<?php

use App\Models\Server;
use App\Models\Setting;
use App\Models\SourceCredential;
use App\Models\Webhook;
use App\Sources\AutoDeploy;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->server = Server::factory()->provisioned()->create();
    $this->autoDeploy = app(AutoDeploy::class);
});

it('needs a public URL and a token', function () {
    expect(fn () => $this->autoDeploy->enable($this->server, 'shop', 'acme/shop'))
        ->toThrow(RuntimeException::class, 'public URL');

    Setting::set(Setting::PUBLIC_URL, 'https://panel.example.com');

    expect(fn () => $this->autoDeploy->enable($this->server, 'shop', 'acme/shop'))
        ->toThrow(RuntimeException::class, 'GitHub token');

    expect(Webhook::query()->count())->toBe(0);
});

it('creates the webhook and keeps it once GitHub reached the panel', function () {
    Setting::set(Setting::PUBLIC_URL, 'https://panel.example.com/');
    SourceCredential::factory()->create();

    Http::fake([
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 77], 201),
        'api.github.com/repos/acme/shop/hooks/77/pings' => function () {
            Webhook::query()->firstOrFail()->recordDelivery('ping');

            return Http::response(null, 204);
        },
        'api.github.com/repos/acme/shop/hooks/77/deliveries?per_page=1' => Http::response([['id' => 1, 'status' => 'OK', 'status_code' => 200, 'event' => 'ping', 'delivered_at' => 'now']]),
    ]);

    $webhook = $this->autoDeploy->enable($this->server, 'shop', 'acme/shop');

    expect($webhook->remote_id)->toBe('77')
        ->and($webhook->url())->toBe("https://panel.example.com/webhooks/github/{$webhook->token}")
        ->and($this->autoDeploy->isEnabled($this->server, 'shop'))->toBeTrue();

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/hooks')
        && $request['config']['url'] === $webhook->url());
});

it('removes the webhook when GitHub could not reach the panel', function () {
    Setting::set(Setting::PUBLIC_URL, 'https://panel.example.com');
    SourceCredential::factory()->create();

    Http::fake([
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 78], 201),
        'api.github.com/repos/acme/shop/hooks/78/pings' => Http::response(null, 204),
        'api.github.com/repos/acme/shop/hooks/78/deliveries?per_page=1' => Http::response([['id' => 1, 'status' => 'couldn\'t connect', 'status_code' => 502, 'event' => 'ping', 'delivered_at' => 'now']]),
        'api.github.com/repos/acme/shop/hooks/78' => Http::response(null, 204),
    ]);

    expect(fn () => $this->autoDeploy->enable($this->server, 'shop', 'acme/shop'))
        ->toThrow(RuntimeException::class, 'HTTP 502');

    expect(Webhook::query()->count())->toBe(0);
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/78'));
});

it('removes the webhook when the ping never arrived here', function () {
    Setting::set(Setting::PUBLIC_URL, 'https://panel.example.com');
    SourceCredential::factory()->create();

    Http::fake([
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 79], 201),
        'api.github.com/repos/acme/shop/hooks/79/pings' => Http::response(null, 204),
        'api.github.com/repos/acme/shop/hooks/79/deliveries?per_page=1' => Http::response([['id' => 1, 'status' => 'OK', 'status_code' => 200, 'event' => 'ping', 'delivered_at' => 'now']]),
        'api.github.com/repos/acme/shop/hooks/79' => Http::response(null, 204),
    ]);

    expect(fn () => $this->autoDeploy->enable($this->server, 'shop', 'acme/shop'))
        ->toThrow(RuntimeException::class, 'did not arrive');

    expect(Webhook::query()->count())->toBe(0);
});

it('disables by removing the hook on GitHub and locally', function () {
    SourceCredential::factory()->create();
    $webhook = Webhook::factory()->for($this->server)->create(['remote_id' => '80']);
    Http::fake(['api.github.com/repos/acme/shop/hooks/80' => Http::response(null, 204)]);

    $this->autoDeploy->disable($this->server, 'shop');

    expect(Webhook::query()->count())->toBe(0);
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/80'));
});
