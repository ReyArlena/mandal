<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Models\Task;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * One task with its output, polling while it is still running.
 */
class ViewTask extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.servers.pages.task';

    #[Locked]
    public int $taskId;

    public function mount(int|string $record, int|string $task): void
    {
        $this->record = $this->resolveRecord($record);

        /** @var Server $server */
        $server = $this->getRecord();

        $this->taskId = $server->tasks()->whereKey($task)->firstOrFail()->getKey();
    }

    public function getTitle(): string
    {
        return $this->task()->type->getLabel().' #'.$this->taskId;
    }

    public function task(): Task
    {
        return Task::query()->findOrFail($this->taskId);
    }
}
