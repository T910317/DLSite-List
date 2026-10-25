<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsOptionReset;
use App\Models\Option;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class IndexWorkDateSettings extends Component
{
    use ConfirmsOptionReset;

    public string $source = Option::INDEX_WORK_DATE_SOURCE_RELEASE;

    public function mount(): void
    {
        $this->fillFromSetting();
    }

    public function render(): View
    {
        return view('livewire.index-work-date-settings', [
            'sourceOptions' => Option::INDEX_WORK_DATE_SOURCE_OPTIONS,
        ]);
    }

    public function save(): void
    {
        $this->validate([
            'source' => ['required', Rule::in(array_keys(Option::INDEX_WORK_DATE_SOURCE_OPTIONS))],
        ]);

        Option::setIndexWorkDateSource($this->source);
        $this->fillFromSetting();
        $this->markSaved('Work release date setting saved.');
    }

    public function resetToDefault(): void
    {
        Option::resetIndexWorkDateSourceToDefault();
        $this->fillFromSetting();
        $this->completeResetWithNotice('Work release date setting reset to default.');
    }

    #[On('options-defaults-reset')]
    public function refreshFromSettings(): void
    {
        $this->fillFromSetting();
        $this->clearSavedNotice();
    }

    public function updated(string $property): void
    {
        if ($property !== 'source') {
            return;
        }

        $this->clearSavedNotice();
    }

    private function fillFromSetting(): void
    {
        $this->source = Option::indexWorkDateSource();
    }
}
