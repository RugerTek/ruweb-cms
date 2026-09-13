<td class="px-4 py-3">
    <a href="{{ $url }}" class="text-sm text-white hover:text-sky-400 transition-colors">{{ $submission->name }}</a>
    <p class="text-xs text-gray-500">{{ $submission->email }}</p>
</td>
<td class="px-4 py-3 text-sm text-gray-400 hidden md:table-cell">{{ $submission->subject ?: '—' }}</td>
<td class="px-4 py-3 text-sm text-gray-400 hidden lg:table-cell max-w-xs truncate">{{ \Illuminate\Support\Str::limit($submission->message, 80) }}</td>
