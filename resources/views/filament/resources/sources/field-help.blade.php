{{-- Source form field help (the "?" next to each field). Static text only. --}}
<div class="grid gap-4 text-sm">
    <section>
        <h3 class="font-semibold text-gray-950 dark:text-white">Why it's needed</h3>
        <p class="mt-1">{{ $help['why'] }}</p>
    </section>

    <section>
        <h3 class="font-semibold text-gray-950 dark:text-white">How to set it</h3>
        <ul class="mt-1 list-disc space-y-1 ps-5">
            @foreach ($help['how'] as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ul>
    </section>

    <section>
        <h3 class="font-semibold text-gray-950 dark:text-white">Example</h3>
        <p class="mt-1 break-words">{{ $help['example'] }}</p>
    </section>
</div>
