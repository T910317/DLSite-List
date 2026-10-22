<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsOptionReset;
use App\Models\Option;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class IndexHoverPreviewSettings extends Component
{
    use ConfirmsOptionReset;

    public bool $enabled = false;

    public function mount(): void
    {
        $this->enabled = Option::indexHoverPreviewEnabled();
    }

    public function render(): View
    {
        return view('livewire.index-hover-preview-settings');
    }

    public function save(): void
    {
        $this->validate([
            'enabled' => ['boolean'],
        ]);

        Option::setIndexHoverPreviewEnabled($this->enabled);
        $this->markSaved('Hover preview setting saved.');
    }

    public function resetToDefault(): void
    {
        Option::resetIndexHoverPreviewEnabledToDefault();
        $this->enabled = false;
        $this->completeResetWithNotice('Hover preview setting reset to default.');
    }

    #[On('options-defaults-reset')]
    public function refreshFromSettings(): void
    {
        $this->enabled = Option::indexHoverPreviewEnabled();
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
