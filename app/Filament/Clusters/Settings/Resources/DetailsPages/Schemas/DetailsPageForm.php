<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages\Schemas;

use App\DetailsPages\GameStatuses;
use App\Enums\GameStatus;
use App\Models\DetailsPage;
use App\Models\DetailsPageEntry;
use App\Models\Server;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DetailsPageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Page')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(64)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set, string $operation): void {
                                if ($operation === 'create' && blank($get('slug'))) {
                                    $set('slug', DetailsPage::generateSlug($state));
                                }
                            })
                            ->helperText('Only shown in the panel.'),
                        TextInput::make('slug')
                            ->label('Address')
                            ->required()
                            ->maxLength(100)
                            ->regex(DetailsPage::SLUG_PATTERN)
                            ->unique(ignoreRecord: true)
                            ->prefix('/p/')
                            ->suffixAction(
                                Action::make('generate')
                                    ->icon(Heroicon::OutlinedArrowPath)
                                    ->tooltip('Generate from the name')
                                    ->action(fn (Get $get, Set $set) => $set('slug', DetailsPage::generateSlug($get('name')))),
                            )
                            ->validationMessages(['regex' => 'Use lowercase letters, digits and single dashes.'])
                            ->helperText('Generated addresses end in a random part so they cannot be guessed. Changing it breaks links already shared.'),
                        TextInput::make('title')
                            ->maxLength(120)
                            ->placeholder('Current Status')
                            ->helperText('Optional heading at the top of the page.'),
                        Textarea::make('intro')
                            ->maxLength(1000)
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Optional text under the heading.'),
                        Toggle::make('hide_branding')
                            ->label('Remove branding')
                            ->helperText('Leaves out the Mandal logo at the bottom of the page.'),
                        Toggle::make('hide_credit')
                            ->label('Remove credit')
                            ->helperText('Leaves out "Made with ♥ by ReyArlena" at the bottom of the page.'),
                    ]),
                Section::make('Servers')
                    ->description('Listed in this order. Drag to reorder.')
                    ->schema([
                        Repeater::make('entries')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort')
                            ->collapsible()
                            ->defaultItems(0)
                            ->addActionLabel('Add server')
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->columns(2)
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::withStatus($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::withStatus($data))
                            ->schema(self::entrySchema()),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function entrySchema(): array
    {
        $is = fn (string $source): Closure => fn (Get $get): bool => $get('status_source') === $source;

        return [
            TextInput::make('name')
                ->required()
                ->maxLength(80)
                ->placeholder('Minecraft (Vanilla)'),
            TextInput::make('version')
                ->label('Game version')
                ->maxLength(80)
                ->placeholder('26.1.2 (Java)'),
            TextInput::make('address')
                ->required()
                ->maxLength(200)
                ->placeholder('play.example.com'),
            TextInput::make('password')
                ->maxLength(200)
                ->helperText('Shown as written, so notes such as "must be added to whitelist" can go here.'),
            ToggleButtons::make('status_source')
                ->label('Status')
                ->options([
                    'none' => 'Hidden',
                    DetailsPageEntry::SOURCE_MANUAL => 'Set by hand',
                    DetailsPageEntry::SOURCE_CONTAINER => 'Docker container',
                ])
                ->grouped()
                ->default('none')
                ->formatStateUsing(fn (?string $state): string => $state ?? 'none')
                ->live()
                ->columnSpanFull(),
            Select::make('status')
                ->label('Shown as')
                ->options([
                    GameStatus::Online->value => GameStatus::Online->getLabel(),
                    GameStatus::Paused->value => GameStatus::Paused->getLabel(),
                ])
                ->formatStateUsing(fn (mixed $state): ?string => $state instanceof GameStatus ? $state->value : $state)
                ->visible($is(DetailsPageEntry::SOURCE_MANUAL))
                ->required($is(DetailsPageEntry::SOURCE_MANUAL))
                ->columnSpanFull(),
            Select::make('link')
                ->label('Container')
                ->searchable()
                ->options(fn (?string $state): array => self::withCurrent(app(GameStatuses::class)->containerOptions(), $state))
                ->afterStateHydrated(fn (Select $component, Get $get) => $component->state(
                    filled($get('server_id')) && filled($get('container')) ? GameStatuses::optionKey($get('server_id'), (string) $get('container')) : null,
                ))
                ->visible($is(DetailsPageEntry::SOURCE_CONTAINER))
                ->required($is(DetailsPageEntry::SOURCE_CONTAINER))
                ->helperText(fn (?DetailsPageEntry $record, ?string $state): string => self::containerHelp($record, $state))
                ->columnSpanFull(),
        ];
    }

    /**
     * Turns the status fields into columns. Fields hidden by the choice are
     * not sent, so whatever the chosen source does not use is cleared; a
     * followed container keeps its stored status until it is checked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withStatus(array $data): array
    {
        $source = $data['status_source'] ?? null;
        $source = in_array($source, [DetailsPageEntry::SOURCE_MANUAL, DetailsPageEntry::SOURCE_CONTAINER], true) ? $source : null;
        $link = $source === DetailsPageEntry::SOURCE_CONTAINER ? GameStatuses::parseOptionKey($data['link'] ?? null) : null;

        unset($data['link']);

        $data['status_source'] = $source;
        $data['server_id'] = $link[0] ?? null;
        $data['container'] = $link[1] ?? null;

        if ($source === DetailsPageEntry::SOURCE_CONTAINER) {
            unset($data['status']);
        } else {
            $data['status'] = $source === DetailsPageEntry::SOURCE_MANUAL ? ($data['status'] ?? null) : null;
        }

        return $data;
    }

    private static function containerHelp(?DetailsPageEntry $record, ?string $state): string
    {
        $saved = $record?->followsContainer() && $state === GameStatuses::optionKey($record->server_id, (string) $record->container);

        if ($saved && $record->status_checked_at !== null) {
            return "{$record->currentStatus()->getLabel()}, checked {$record->status_checked_at->diffForHumans()}. Online while it runs, Paused when it is stopped.";
        }

        return 'Online while it runs, Paused when it is stopped. Checked when you save, then whenever the panel reads that server.';
    }

    /**
     * Keeps a saved container selectable when its server cannot be read now.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private static function withCurrent(array $options, ?string $state): array
    {
        if (blank($state) || array_key_exists($state, $options) || ($link = GameStatuses::parseOptionKey($state)) === null) {
            return $options;
        }

        $server = Server::query()->find($link[0])?->name ?? 'removed server';

        return [$state => "{$link[1]} ({$server})", ...$options];
    }
}
