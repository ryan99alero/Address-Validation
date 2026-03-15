@props(['headers' => [], 'rows' => []])

@if(count($headers) && count($rows))
    <div class="overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead>
                <tr>
                    @foreach($headers as $header)
                        <th class="px-2 py-1 text-left font-medium text-gray-600 dark:text-gray-400">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($row as $cell)
                            <td class="px-2 py-1 text-gray-700 dark:text-gray-300 truncate max-w-[150px]">{{ $cell ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 italic">No preview available</p>
@endif
