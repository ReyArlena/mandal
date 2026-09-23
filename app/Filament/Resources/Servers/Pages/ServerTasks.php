<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Models\Task;
use BackedEnum;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The provisioning and other long-running jobs the panel has run on a server.
 */
class ServerTasks extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $title = 'Tasks';

    protected string $view = 'filament.servers.pages.tasks';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /**
     * @return Collection<int, Task>
     */
    public function tasks(): Collection
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server->tasks()->with('user')->latest('id')->limit(50)->get();
    }

    public function hasRunningTask(): bool
    {
        return $this->tasks()->contains(fn (Task $task): bool => ! $task->isFinished());
    }
}
