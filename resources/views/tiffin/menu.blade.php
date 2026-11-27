@extends('layouts.staff')
@section('content')
    <header class="page">
        <div>
            <p class="kicker">Kitchen</p>
            <h1>Tiffin menu</h1>
            <p class="lede">What {{ $currentMerchant->name }} cooks each day. Breakfast, lunch, and dinner can each have a veg dish and a non-veg dish. Customer portions still follow each plan.</p>
        </div>
    </header>
    <form class="card" method="post" action="{{ route('tiffin-menu.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <label>Day
                <select name="day_of_week" required>
                    @foreach ($days as $number => $label)
                        <option value="{{ $number }}" @selected((int) old('day_of_week', 1) === $number)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Meal
                <select name="slot" required>
                    @foreach ($slots as $value => $label)
                        <option value="{{ $value }}" @selected(old('slot', 'lunch') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Diet
                <select name="diet" required>
                    @foreach ($diets as $value => $label)
                        <option value="{{ $value }}" @selected(old('diet', 'veg') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Dish
                <input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="Rajma, rice, roti">
            </label>
            <label>Photo
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
            </label>
        </div>
        <p class="help">Photo is optional. It shows here and on the packing list for that day.</p>
        <button type="submit">Add to menu</button>
    </form>
    <div class="week">
        @foreach ($days as $number => $label)
            <article class="card day-card">
                <h2>{{ $label }}</h2>
                @foreach ($slots as $slot => $slotLabel)
                    <section class="meal-block">
                        <h3>{{ $slotLabel }}</h3>
                        @foreach ($diets as $diet => $dietLabel)
                            @php($planned = $items->where('day_of_week', $number)->where('slot', $slot)->where('diet', $diet))
                            <div class="diet">
                                <span class="pill {{ $diet === 'veg' ? 'good' : 'bad' }}">{{ $dietLabel }}</span>
                                <div>
                                    @forelse ($planned as $item)
                                        <div class="dish">
                                            <span class="dish-name">
                                                @if ($item->photoUrl())
                                                    <img class="dish-photo" src="{{ $item->photoUrl() }}" alt="{{ $item->name }}">
                                                @endif
                                                {{ $item->name }}
                                            </span>
                                            <span class="dish-actions">
                                                <a href="{{ route('tiffin-menu.edit', $item) }}">Edit</a>
                                                <form method="post" action="{{ route('tiffin-menu.destroy', $item) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="quiet" type="submit">Remove</button>
                                                </form>
                                            </span>
                                        </div>
                                    @empty
                                        <p class="empty">Nothing planned.</p>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </article>
        @endforeach
    </div>
@endsection
