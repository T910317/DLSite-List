<div>
    <form wire:submit.prevent="save" class="option-form">
        <x-options.switch wire:model.live="enabled" :help="__('When enabled, resting on an Index cover opens a large preview with the saved cover, sample images, and work details. Scroll the mouse wheel to change images. Clicking the cover still works as before.')">
            {{ __('Show work details and sample images when hovering a cover') }}
        </x-options.switch>

        @error('enabled')
            <div class="text-error">{{ $message }}</div>
        @enderror

        <div class="option-actions option-actions--inline">
            <button type="submit"
                class="tag tag--soft tag--lg is-clickable">{{ __('Save hover preview setting') }}</button>
            @if ($saved)
                <span class="saved-notice">{{ __($notice) }}</span>
            @endif
            <button type="button" class="tag tag--soft tag--lg is-clickable option-reset-button"
                wire:click="askResetToDefault">
                {{ __('Reset to default') }}
            </button>
        </div>

        @include('livewire.partials.options-reset-confirmation-modal', [
            'open' => $confirmingResetToDefault,
            'modalId' => 'index-hover-preview-reset-modal',
            'message' => 'Reset this hover preview setting to its default?',
            'confirmLabel' => 'Reset to default',
            'confirmAction' => 'resetToDefault',
            'cancelAction' => 'cancelResetToDefault',
        ])
    </form>
</div>
