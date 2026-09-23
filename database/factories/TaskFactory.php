<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => TaskType::Provision,
            'status' => TaskStatus::Pending,
            'options' => [],
            'output' => '',
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Running,
            'started_at' => now()->subMinute(),
            'output' => "==> Base packages\n+ apt-get update -q\n",
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Succeeded,
            'exit_code' => 0,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'output' => "==> Base packages\n\nProvisioning finished.\n",
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Failed,
            'exit_code' => 1,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'output' => "error: provisioning cannot continue:\nApache is installed\n",
        ]);
    }
}
