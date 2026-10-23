{{-- Non-blocking notice: other editions of the work are already in the library. --}}
@if (!empty($works))
    <div class="related-editions-warning" role="status">
        <span class="related-editions-warning__title">{{ __('Other editions of this work are already in your library:') }}</span>
        <ul class="related-editions-warning__list">
            @foreach ($works as $work)
                <li>
                    <a href="{{ route('products.edit', ['product' => $work['code']]) }}"
                        @if ($linkTarget ?? null) target="{{ $linkTarget }}" @endif
                        @if (($linkTarget ?? null) === '_blank') rel="noopener" @endif>{{ $work['code'] }}</a>
                    @if (filled($work['title'] ?? null))
                        <span class="related-editions-warning__work-title">{{ $work['title'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
