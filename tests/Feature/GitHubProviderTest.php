<?php

use App\Sources\GitHubProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->github = new GitHubProvider('github_pat_test');
});

it('reads the token account and expiry', function () {
    Http::fake(['api.github.com/user' => Http::response(['login' => 'acme'], 200, ['github-authentication-token-expiration' => '2027-01-01 00:00:00 UTC'])]);

    $info = $this->github->tokenInfo();

    expect($info['account'])->toBe('acme')
        ->and($info['expires_at']?->toDateString())->toBe('2027-01-01');

    Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer github_pat_test')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
});

it('rejects a bad token', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    expect(fn () => $this->github->tokenInfo())->toThrow(RuntimeException::class, 'rejected');
});

it('adds a read-only deploy key', function () {
    Http::fake(['api.github.com/repos/acme/shop/keys' => Http::response(['id' => 42], 201)]);

    expect($this->github->addDeployKey('acme/shop', 'mandal shop@web-1', 'ssh-ed25519 AAAA shop@web-1'))->toBe('42');

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && $request['title'] === 'mandal shop@web-1'
        && $request['read_only'] === true);
});

it('returns the existing key when GitHub already has it', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/keys*' => Http::sequence()
            ->push(['message' => 'Validation Failed', 'errors' => [['message' => 'key is already in use']]], 422)
            ->push([['id' => 7, 'key' => 'ssh-ed25519 AAAA', 'title' => 'old']], 200),
    ]);

    expect($this->github->addDeployKey('acme/shop', 'x', 'ssh-ed25519 AAAA shop@web-1'))->toBe('7');
});

it('removes every key matching the public key', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/keys?per_page=100' => Http::response([
            ['id' => 1, 'key' => 'ssh-ed25519 AAAA', 'title' => 'a'],
            ['id' => 2, 'key' => 'ssh-ed25519 BBBB', 'title' => 'b'],
        ]),
        'api.github.com/repos/acme/shop/keys/*' => Http::response(null, 204),
    ]);

    $this->github->removeDeployKey('acme/shop', 'ssh-ed25519 AAAA comment');

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/keys/1'));
    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/keys/2'));
});

it('creates, pings and inspects a push webhook', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 99], 201),
        'api.github.com/repos/acme/shop/hooks/99/pings' => Http::response(null, 204),
        'api.github.com/repos/acme/shop/hooks/99/deliveries?per_page=1' => Http::response([['id' => 1, 'status' => 'OK', 'status_code' => 200, 'event' => 'ping', 'delivered_at' => '2026-10-27T05:00:00Z']]),
        'api.github.com/repos/acme/shop/hooks/99' => Http::response(null, 204),
    ]);

    $id = $this->github->addWebhook('acme/shop', 'https://panel.example.com/webhooks/github/abc', 'shh');
    $this->github->pingWebhook('acme/shop', $id);
    $delivery = $this->github->lastDelivery('acme/shop', $id);
    $this->github->removeWebhook('acme/shop', $id);

    expect($id)->toBe('99')
        ->and($delivery['event'])->toBe('ping')
        ->and($delivery['status_code'])->toBe(200);

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/hooks')
        && $request['config']['secret'] === 'shh'
        && $request['config']['content_type'] === 'json'
        && $request['events'] === ['push']);
});

it('refuses repository names and ids that are not plain', function () {
    Http::fake();

    expect(fn () => $this->github->listBranches('../evil'))->toThrow(RuntimeException::class);
    expect(fn () => $this->github->removeWebhook('acme/shop', '1;2'))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('validates signatures with the documented test vector', function () {
    $secret = "It's a Secret to Everybody";
    $body = 'Hello, World!';
    $request = Request::create('/webhooks/github/x', 'POST', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17'], $body);
    $tampered = Request::create('/webhooks/github/x', 'POST', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17'], 'Hello, World?');
    $missing = Request::create('/webhooks/github/x', 'POST', [], [], [], [], $body);

    expect($this->github->verifyWebhook($request, $secret))->toBeTrue()
        ->and($this->github->verifyWebhook($tampered, $secret))->toBeFalse()
        ->and($this->github->verifyWebhook($missing, $secret))->toBeFalse();
});

it('parses push payloads', function () {
    $commit = str_repeat('a', 40);

    expect($this->github->parsePush(['ref' => 'refs/heads/main', 'after' => $commit]))->toBe(['branch' => 'main', 'commit' => $commit])
        ->and($this->github->parsePush(['ref' => 'refs/tags/v1', 'after' => $commit]))->toBeNull()
        ->and($this->github->parsePush(['ref' => 'refs/heads/main', 'after' => str_repeat('0', 40)]))->toBeNull();
});
