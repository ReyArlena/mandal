<?php

namespace App\DetailsPages;

use App\Enums\GameStatus;
use App\Models\DetailsPage;
use App\Models\DetailsPageEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Server details pages as a JSON file, to move them to another panel. The
 * file leaves out which Docker container an entry follows, since servers
 * and container names differ between panels: such entries come back set to
 * follow a container, waiting for one to be picked. Importing only adds;
 * a page whose address is already taken here is skipped.
 */
final class DetailsPagesTransfer
{
    public const string FORMAT = 'mandal-details-pages';

    public const int VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $pages = DetailsPage::query()->with('entries')->orderBy('name')->get();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'pages' => $pages->map(fn (DetailsPage $page): array => [
                'name' => $page->name,
                'slug' => $page->slug,
                'title' => $page->title,
                'intro' => $page->intro,
                'hide_branding' => $page->hide_branding,
                'hide_credit' => $page->hide_credit,
                'entries' => $page->entries->map(fn (DetailsPageEntry $entry): array => [
                    'name' => $entry->name,
                    'version' => $entry->version,
                    'address' => $entry->address,
                    'password' => $entry->password,
                    'status_source' => $entry->status_source,
                    'status' => $entry->status_source === DetailsPageEntry::SOURCE_MANUAL ? $entry->status?->value : null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Adds the pages in an export, keeping their addresses.
     *
     * @return array{created: list<string>, skipped: list<string>}
     *
     * @throws ValidationException when the file is not an export or a field is invalid
     */
    public function import(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw ValidationException::withMessages(['file' => 'This is not a server details pages export from Mandal.']);
        }

        $sources = [DetailsPageEntry::SOURCE_MANUAL, DetailsPageEntry::SOURCE_CONTAINER];
        $data = Validator::make($data, [
            'version' => ['required', 'integer', 'max:'.self::VERSION],
            'pages' => ['present', 'array'],
            'pages.*.name' => ['required', 'string', 'max:64'],
            'pages.*.slug' => ['required', 'string', 'max:100', 'regex:'.DetailsPage::SLUG_PATTERN],
            'pages.*.title' => ['nullable', 'string', 'max:120'],
            'pages.*.intro' => ['nullable', 'string', 'max:1000'],
            'pages.*.hide_branding' => ['boolean'],
            'pages.*.hide_credit' => ['boolean'],
            'pages.*.entries' => ['present', 'array'],
            'pages.*.entries.*.name' => ['required', 'string', 'max:80'],
            'pages.*.entries.*.version' => ['nullable', 'string', 'max:80'],
            'pages.*.entries.*.address' => ['required', 'string', 'max:200'],
            'pages.*.entries.*.password' => ['nullable', 'string', 'max:200'],
            'pages.*.entries.*.status_source' => ['nullable', Rule::in($sources)],
            'pages.*.entries.*.status' => ['nullable', Rule::in([GameStatus::Online->value, GameStatus::Paused->value])],
        ])->validate();

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($data, &$created, &$skipped): void {
            foreach ($data['pages'] as $page) {
                if (DetailsPage::query()->where('slug', $page['slug'])->exists()) {
                    $skipped[] = $page['name'];

                    continue;
                }

                $record = DetailsPage::query()->create([
                    'name' => $page['name'],
                    'slug' => $page['slug'],
                    'title' => $page['title'] ?? null,
                    'intro' => $page['intro'] ?? null,
                    'hide_branding' => (bool) ($page['hide_branding'] ?? false),
                    'hide_credit' => (bool) ($page['hide_credit'] ?? false),
                ]);

                foreach (array_values($page['entries']) as $sort => $entry) {
                    $source = $entry['status_source'] ?? null;
                    $manual = $source === DetailsPageEntry::SOURCE_MANUAL;

                    $record->entries()->create([
                        'sort' => $sort,
                        'name' => $entry['name'],
                        'version' => $entry['version'] ?? null,
                        'address' => $entry['address'],
                        'password' => $entry['password'] ?? null,
                        // An entry set by hand without a status reads as hidden.
                        'status_source' => $manual && empty($entry['status']) ? null : $source,
                        'status' => $manual ? ($entry['status'] ?? null) : null,
                    ]);
                }

                $created[] = $page['name'];
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }
}
