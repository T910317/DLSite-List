<div>
    <form wire:submit.prevent="save" class="option-form">
        <div class="option-radio-grid">
            @foreach ($sourceOptions as $value => $label)
                <label class="option-radio">
                    <input type="radio" wire:model.live="source" value="{{ $value }}">
                    <span>{{ __($label) }}</span>
                </label>
            @endforeach
        </div>
        <p class="helper-text">
            {{ __('The Work release date column and its sort follow this choice. Release date is when DLsite first published the work. Update date is the last update DLsite recorded.') }}
        </p>

        @error('source')
            <div class="text-error">{{ $message }}</div>
        @enderror

        <div class="option-actions option-actions--inline">
            <button type="submit" class="tag tag--soft tag--lg is-clickable">{{ __('Save work date') }}</button>
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
            'modalId' => 'index-work-date-reset-modal',
            'message' => 'Reset this setting to its default?',
            'confirmLabel' => 'Reset to default',
            'confirmAction' => 'resetToDefault',
            'cancelAction' => 'cancelResetToDefault',
        ])
    </form>
</div>
