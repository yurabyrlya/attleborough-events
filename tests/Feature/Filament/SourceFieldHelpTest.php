<?php

use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Schemas\SourceFieldHelp;
use App\Models\Source;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * The "?" helpers on the source form: every field explains why it exists,
 * how to set it and gives an example, in a dialog that changes nothing.
 */

beforeEach(function () {
    actingAs(ingestAdmin());
});

function sourceFieldHelpAction(string $topic): TestAction
{
    $action = TestAction::make('help_'.str_replace('.', '_', $topic));

    return $topic === 'title_rules'
        ? $action->schemaComponent('title-rules-section', 'form')
        : $action->schemaComponent($topic, 'form');
}

it('explains every source field with why, how and an example', function (string $topic) {
    $help = SourceFieldHelp::TOPICS[$topic];

    livewire(CreateSource::class)
        ->assertActionVisible(sourceFieldHelpAction($topic))
        ->mountAction(sourceFieldHelpAction($topic))
        ->assertMountedActionModalSee("Why it's needed", false)
        ->assertMountedActionModalSee([
            $help['label'],
            $help['summary'],
            'How to set it',
            ...$help['how'],
            'Example',
            $help['why'],
            $help['example'],
        ]);
})->with(array_keys(SourceFieldHelp::TOPICS));

it('shows the same help when editing and saves nothing when the dialog closes', function () {
    $source = Source::factory()->create(['name' => 'Attleborough Baptist Church']);

    livewire(EditSource::class, ['record' => $source->getRouteKey()])
        ->mountAction(sourceFieldHelpAction('parser_config.identity'))
        ->assertMountedActionModalSee(SourceFieldHelp::TOPICS['parser_config.identity']['example'])
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($source->fresh()->updated_at->equalTo($source->updated_at))->toBeTrue();
});

it('refuses a help topic that does not exist', function () {
    SourceFieldHelp::action('nonsense');
})->throws(InvalidArgumentException::class);
