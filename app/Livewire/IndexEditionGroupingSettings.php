<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsOptionReset;
use App\Models\Option;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class IndexEditionGroupingSettings extends Component
{
    use ConfirmsOptionReset;

    public bool $enabled = false;

    public function mount(): void
    {
        $this->enabled = Option::indexGroupEditionsEnabled();
    }

    public function render(): View
    {
        return view('livewire.index-edition-grouping-settings');
    }

    public function save(): void
    {
        $this->validate([
            'enabled' => ['boolean'],
        ]);

        Option::setIndexGroupEditionsEnabled($this->enabled);
        $this->markSaved('Edition grouping setting saved.');
    }

    public function resetToDefault(): void
    {
        Option::resetIndexGroupEditionsEnabledToDefault();
        $this->enabled = Option::indexGroupEditionsEnabled();
        $this->completeResetWithNotice('Edition grouping setting reset to default.');
    }

    #[On('options-defaults-reset')]
    public function refreshFromSettings(): void
    {
        $this->enabled = Option::indexGroupEditionsEnabled();
        $this->clearSavedNotice();
    }

    public function updated(string $property): void
    {
        if ($property !== 'enabled') {
            return;
        }

        $this->clearSavedNotice();
    }
}
