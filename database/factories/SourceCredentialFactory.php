<?php

namespace Database\Factories;

use App\Enums\SourceProviderType;
use App\Models\SourceCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceCredential>
 */
class SourceCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => SourceProviderType::GitHub,
            'name' => 'Personal token',
            'token' => 'github_pat_'.fake()->regexify('[A-Za-z0-9]{40}'),
            'account' => fake()->userName(),
            'expires_at' => now()->addMonths(3),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
