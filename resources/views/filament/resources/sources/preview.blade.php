{{--
    Latest test run of a source (D-01). Escaped output only: titles come
    from the remote feed. While the source has a queued or running run the
    wrapper polls every 3 seconds (whole page re-render, so the header
    actions follow); otherwise nothing polls.

    @var \App\Models\SourceRun|null $run
    @var bool $polling
    @var int $wouldCreate
    @var array<string, int> $skipped
    @var list<array{title: string, starts: string, verdict: string, reason: string, duplicate: string|null}> $rows
    @var string|null $checkedAt
--}}
<div @if ($polling) wire:poll.3s @endif class="grid gap-4 text-sm">
    @if ($run === null)
        <p>No test run yet. Click "Test run" to see what this source would draft. Nothing is drafted.</p>
    @elseif ($run->status->isActive())
        <p role="status">Checking the source… This page updates by itself.</p>
    @elseif ($run->status === \App\Enums\RunStatus::Succeeded)
        @if ($polling)
            <p role="status">Another check of this source is running. This page updates by itself.</p>
        @endif

        <div class="grid gap-1">
            <p class="text-base font-semibold">Would create {{ $wouldCreate }} {{ $wouldCreate === 1 ? 'draft' : 'drafts' }}</p>
            <p class="text-gray-600 dark:text-gray-300">
                Skipped:
                @foreach ($skipped as $label => $count)
                    {{ $label }} {{ $count }}@if (! $loop->last) · @endif
                @endforeach
            </p>
            @if ($checkedAt !== null)
                <p class="text-gray-600 dark:text-gray-300">Checked {{ $checkedAt }}</p>
            @endif
        </div>

        @if ($rows === [])
            <p>The feed had no items.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th scope="col" class="py-2 pe-4 font-medium">Title</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Date and time</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Verdict</th>
                            <th scope="col" class="py-2 font-medium">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                <td class="py-2 pe-4 break-words">
                                    {{ $row['title'] }}
                                    @if ($row['duplicate'] !== null)
                                        <span class="block text-warning-600 dark:text-warning-400">{{ $row['duplicate'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pe-4 whitespace-nowrap">{{ $row['starts'] }}</td>
                                <td class="py-2 pe-4">{{ $row['verdict'] }}</td>
                                <td class="py-2">{{ $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
        <div class="grid gap-1">
            <p class="text-base font-semibold">
                {{ $run->status === \App\Enums\RunStatus::Blocked ? 'Test run blocked by robots.txt' : 'Test run failed' }}
            </p>
            @if ($run->error_message !== null)
                <p class="break-words text-gray-600 dark:text-gray-300">{{ $run->error_message }}</p>
            @endif
            <p>Nothing was drafted. Fix the feed address or wait for the site, then run another test.</p>
        </div>
    @endif
</div>
