<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\User;
use App\Review\Exceptions\RulesNotSaved;
use App\Review\SaveRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The one Rules screen (ADM-04, research D16): the admin tunes how drafts
 * are categorised without a deploy. One "Save rules" action hands the whole
 * form state to App\Review\SaveRules, which checks it and writes everything
 * in one transaction. This page never writes rows itself.
 *
 * @property-read Schema $form
 */
class Rules extends Page
{
    protected string $view = 'filament.pages.rules';

    protected static ?string $title = 'Rules';

    protected static ?string $navigationLabel = 'Rules';

    protected static ?string $slug = 'rules';

    protected static string|UnitEnum|null $navigationGroup = 'Review';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 50;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Same rule as the panel gate (User::canAccessPanel), kept in one place.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function mount(): void
    {
        $this->fillFromDatabase();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Rules')
                    ->tabs([
                        Tab::make('Categories')
                            ->schema([
                                Repeater::make('categories')
                                    ->hiddenLabel()
                                    ->helperText('New categories use the generic icon on the public site. Slugs never change because links use them.')
                                    ->table([
                                        TableColumn::make('Name'),
                                        TableColumn::make('Slug'),
                                        TableColumn::make('Icon'),
                                        TableColumn::make('Sort'),
                                    ])
                                    ->schema([
                                        Hidden::make('id'),
                                        TextInput::make('name')
                                            ->label('Name')
                                            ->required()
                                            ->maxLength(SaveRules::CATEGORY_NAME_MAX_LENGTH),
                                        TextInput::make('slug')
                                            ->label('Slug')
                                            ->placeholder('Set from the name')
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('icon')
                                            ->label('Icon')
                                            ->placeholder(SaveRules::DEFAULT_ICON)
                                            ->maxLength(100),
                                        TextInput::make('sort')
                                            ->label('Sort')
                                            ->integer()
                                            ->minValue(0)
                                            ->maxValue(SaveRules::SORT_MAX)
                                            ->required(),
                                    ])
                                    ->deleteAction(fn (Action $action): Action => $action->hidden(
                                        fn (array $arguments, Repeater $component): bool => self::isOtherRow($component, $arguments),
                                    ))
                                    ->reorderable(false)
                                    ->addActionLabel('Add category')
                                    ->defaultItems(0),
                            ]),
                        Tab::make('Keyword rules')
                            ->schema([
                                Repeater::make('keyword_rules')
                                    ->hiddenLabel()
                                    ->helperText("Lower numbers are checked first. Words match whole words only, so 'quiz' doesn't match 'quizzical'.")
                                    ->table([
                                        TableColumn::make('Keyword'),
                                        TableColumn::make('Category'),
                                        TableColumn::make('Priority'),
                                    ])
                                    ->schema([
                                        Hidden::make('id'),
                                        TextInput::make('keyword')
                                            ->label('Keyword')
                                            ->required()
                                            ->maxLength(SaveRules::KEYWORD_MAX_LENGTH),
                                        Select::make('category_id')
                                            ->label('Category')
                                            ->options(fn (): array => Category::query()->orderBy('sort')->orderBy('id')->pluck('name', 'id')->all())
                                            ->required(),
                                        TextInput::make('priority')
                                            ->label('Priority')
                                            ->integer()
                                            ->minValue(SaveRules::PRIORITY_MIN)
                                            ->maxValue(SaveRules::PRIORITY_MAX)
                                            ->default(SaveRules::DEFAULT_PRIORITY)
                                            ->required(),
                                    ])
                                    ->reorderable(false)
                                    ->addActionLabel('Add keyword rule')
                                    ->defaultItems(0),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save rules')
                ->action(fn () => $this->save()),
        ];
    }

    private function save(): void
    {
        /** @var User $admin */
        $admin = auth()->user();

        try {
            app(SaveRules::class)($this->form->getState(), $admin);
        } catch (RulesNotSaved $refused) {
            Notification::make()
                ->danger()
                ->title('Rules not saved')
                ->body(implode(' ', $refused->problems))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->success()->title('Rules saved')->send();

        $this->fillFromDatabase();
    }

    /**
     * The Other row has no delete button (SaveRules refuses it anyway).
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function isOtherRow(Repeater $component, array $arguments): bool
    {
        $items = $component->getRawState();
        $key = $arguments['item'] ?? null;

        return is_array($items) && is_string($key) && (($items[$key]['slug'] ?? null) === 'other');
    }

    private function fillFromDatabase(): void
    {
        $this->form->fill([
            'categories' => Category::query()
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'icon' => $category->icon,
                    'sort' => $category->sort,
                ])
                ->all(),
            'keyword_rules' => CategoryKeywordRule::query()
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (CategoryKeywordRule $rule): array => [
                    'id' => $rule->id,
                    'keyword' => $rule->keyword,
                    'category_id' => $rule->category_id,
                    'priority' => $rule->priority,
                ])
                ->all(),
        ]);
    }
}
