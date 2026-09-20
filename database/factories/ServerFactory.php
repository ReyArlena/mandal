<?php

namespace Database\Factories;

use App\Enums\ServerSetupMode;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'host' => fake()->ipv4(),
            'port' => 22,
            'ssh_user' => 'mandal',
            'setup_mode' => ServerSetupMode::Fresh,
        ];
    }

    public function withPinnedHostKey(): static
    {
        return $this->state(fn (): array => [
            'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1',
            'connected_at' => now(),
        ]);
    }

    public function provisioned(): static
    {
        return $this->withPinnedHostKey()->state(fn (): array => [
            'provisioned_at' => now(),
        ]);
    }
}
