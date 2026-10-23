@props(['rows' => []])

{{-- Links open in a new tab so unsaved edits on this page are kept. --}}
<tr class="related-editions-row">
    <td width="130" class="form-table-cell" valign="top">{{ __('Other editions') }}</td>
    <td class="form-table-cell">
        <ul class="related-editions-list">
            @foreach ($rows as $row)
                <li class="related-editions-list__item">
                    <a href="{{ $row['url'] }}" target="_blank"
                        rel="{{ $row['in_library'] ? 'noopener' : 'noopener noreferrer' }}">{{ $row['code'] }}</a>
                    @php
                        $details = array_filter([
                            \App\Support\RelatedEditions::typeLabel($row['type']),
                            $row['label'],
                        ]);
                    @endphp
                    @if ($details !== [])
                        <span class="related-editions-list__type">({{ implode(': ', $details) }})</span>
                    @endif
                    @if ($row['in_library'])
                        <span class="related-editions-list__title">{{ $row['title'] }}</span>
                    @else
                        <span class="related-editions-list__missing">{{ __('Not in your library') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </td>
    <td class="form-table-cell form-table-cell--long-spacer" aria-hidden="true"></td>
</tr>
