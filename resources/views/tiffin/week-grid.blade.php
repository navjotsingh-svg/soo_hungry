@php
    $owner = (string) ($scheduleFor ?? ($plan->customer_id ?? 'new'));
    $posted = (string) old('schedule_for') === $owner;
    $selected = old('slots', []);
@endphp
<table class="week-grid">
    <thead>
        <tr>
            <th></th>
            @foreach ($days as $number => $label)
                <th>{{ substr($label, 0, 3) }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'] as $slot => $slotLabel)
            <tr>
                <th>{{ $slotLabel }}</th>
                @foreach ($days as $number => $label)
                    @php
                        $chosen = (array) ($selected[$number] ?? $selected[(string) $number] ?? []);
                        $on = $posted
                            ? in_array($slot, $chosen, true)
                            : ($plan && $plan->serves((int) $number, $slot));
                    @endphp
                    <td>
                        <label class="choice">
                            <input type="checkbox" name="slots[{{ $number }}][]" value="{{ $slot }}" @checked($on)>
                            <span class="sr-only">{{ $slotLabel }} {{ $label }}</span>
                        </label>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
