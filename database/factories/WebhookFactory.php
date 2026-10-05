<?php

namespace Database\Factories;

use App\Enums\SourceProviderType;
use App\Models\Server;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory()->provisioned(),
            'app' => 'shop',
            'provider' => SourceProviderType::GitHub,
            'repo' => 'acme/shop',
            'token' => Str::random(48),
            'secret' => Str::random(48),
            'remote_id' => (string) fake()->numberBetween(1000, 9999),
        ];
    }
}
