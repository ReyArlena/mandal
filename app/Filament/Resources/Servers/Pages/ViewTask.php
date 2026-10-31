<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
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

    /**
     * After a Docker app starts or restarts, its containers are where the
     * progress shows next: the Logs page on their output, refreshing live.
     */
    public function containerLogsUrl(): ?string
    {
        $task = $this->task();
        $name = (string) ($task->options['name'] ?? '');

        if ($task->type !== TaskType::Control || $task->status !== TaskStatus::Succeeded || ! in_array($task->options['action'] ?? null, ['start', 'restart'], true) || $name === '') {
            return null;
        }

        return ServerResource::getUrl('logs', ['record' => $this->getRecord(), 'source' => "docker:{$name}", 'live' => 1]);
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
