<section class="panel" @if ($run->isActive()) wire:poll.1s="refreshProgress" @endif>
    <h2>{{ __('Progress') }}</h2>
    <div class="progress-summary">
        <span>{{ trans_choice(':processed / :total work processed|:processed / :total works processed', $run->total_count, ['processed' => $run->processed_count, 'total' => $run->total_count]) }}</span>
        <span>{{ $run->statusLabel() }}</span>
    </div>
    <div class="progress-track">
        <div class="progress-fill" style="width: {{ $progressPercent }}%"></div>
    </div>
    @if ($fetchingResults->isNotEmpty())
        <div class="refetch-current-works">
            <span class="refetch-current-works__label">{{ __('Now fetching:') }}</span>
            <ul>
                @foreach ($fetchingResults as $fetchingResult)
                    <li wire:key="refetch-current-work-{{ $fetchingResult->getKey() }}">
                        <strong>{{ $fetchingResult->product_id }}</strong>
                        @if (filled($fetchingResult->product?->work_name))
                            <span>{{ $fetchingResult->product->work_name }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="summary-grid">
        <div>{{ __('Fetched') }} <strong>{{ $run->fetched_count }}</strong></div>
        <div>{{ __('Failed') }} <strong>{{ $run->failed_count }}</strong></div>
        <div>{{ __('Total') }} <strong>{{ $run->total_count }}</strong></div>
    </div>
    @if ($run->canBeCancelled())
        <form method="POST" action="{{ route('options.refetch.cancel', $run) }}" class="option-actions">
            @csrf
            <button type="submit" class="tag tag--outline tag--md is-clickable">
                {{ __('Cancel Refetch') }}
            </button>
        </form>
    @endif
</section>
