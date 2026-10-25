<?php

namespace Tests\Feature;

use App\Livewire\IndexWorkDateSettings;
use App\Models\Option;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class IndexWorkDateSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_the_release_date(): void
    {
        Livewire::test(IndexWorkDateSettings::class)
            ->assertSet('source', Option::INDEX_WORK_DATE_SOURCE_RELEASE);

        $this->assertSame(Option::INDEX_WORK_DATE_SOURCE_RELEASE, Option::indexWorkDateSource());
    }

    public function test_it_saves_the_update_date_only_on_submit(): void
    {
        $component = Livewire::test(IndexWorkDateSettings::class)
            ->set('source', Option::INDEX_WORK_DATE_SOURCE_UPDATED);

        $this->assertSame(Option::INDEX_WORK_DATE_SOURCE_RELEASE, Option::indexWorkDateSource());

        $component
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Option::INDEX_WORK_DATE_SOURCE_UPDATED, Option::indexWorkDateSource());
    }

    public function test_it_rejects_unknown_sources(): void
    {
        Livewire::test(IndexWorkDateSettings::class)
            ->set('source', 'created')
            ->call('save')
            ->assertHasErrors(['source']);

        $this->assertSame(Option::INDEX_WORK_DATE_SOURCE_RELEASE, Option::indexWorkDateSource());
    }

    public function test_the_model_setter_rejects_unknown_sources(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Option::setIndexWorkDateSource('created');
    }

    public function test_reset_returns_to_the_release_date_after_confirmation(): void
    {
        Option::setIndexWorkDateSource(Option::INDEX_WORK_DATE_SOURCE_UPDATED);

        Livewire::test(IndexWorkDateSettings::class)
            ->assertSet('source', Option::INDEX_WORK_DATE_SOURCE_UPDATED)
            ->call('askResetToDefault')
            ->assertSet('confirmingResetToDefault', true)
            ->call('resetToDefault')
            ->assertSet('source', Option::INDEX_WORK_DATE_SOURCE_RELEASE)
            ->assertSet('confirmingResetToDefault', false);

        $this->assertSame(Option::INDEX_WORK_DATE_SOURCE_RELEASE, Option::indexWorkDateSource());
    }

    public function test_cancelling_reset_keeps_the_saved_update_date(): void
    {
        Option::setIndexWorkDateSource(Option::INDEX_WORK_DATE_SOURCE_UPDATED);

        Livewire::test(IndexWorkDateSettings::class)
            ->call('askResetToDefault')
            ->call('cancelResetToDefault')
            ->assertSet('confirmingResetToDefault', false);

        $this->assertSame(Option::INDEX_WORK_DATE_SOURCE_UPDATED, Option::indexWorkDateSource());
    }
}
