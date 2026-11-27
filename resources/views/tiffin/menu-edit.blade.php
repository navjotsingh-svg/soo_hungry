@extends('layouts.staff')
@section('content')
    <a class="back" href="{{ route('tiffin-menu.index') }}">Back to menu</a>
    <header class="page">
        <div>
            <p class="kicker">Kitchen</p>
            <h1>Edit dish</h1>
            <p class="lede">Change the day, meal, diet, name, or photo for {{ $item->name }}.</p>
        </div>
    </header>
    <form class="card" method="post" action="{{ route('tiffin-menu.update', $item) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <div class="row">
            <label>Day
                <select name="day_of_week" required>
                    @foreach ($days as $number => $label)
                        <option value="{{ $number }}" @selected((int) old('day_of_week', $item->day_of_week) === $number)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Meal
                <select name="slot" required>
                    @foreach ($slots as $value => $label)
                        <option value="{{ $value }}" @selected(old('slot', $item->slot) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Diet
                <select name="diet" required>
                    @foreach ($diets as $value => $label)
                        <option value="{{ $value }}" @selected(old('diet', $item->diet) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Dish
                <input name="name" value="{{ old('name', $item->name) }}" required maxlength="120">
            </label>
            <label>Photo
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
            </label>
        </div>
        @if ($item->photoUrl())
            <figure class="menu-dish">
                <img src="{{ $item->photoUrl() }}" alt="{{ $item->name }}">
                <figcaption>{{ $item->name }}</figcaption>
            </figure>
            <label class="check">
                <input type="checkbox" name="remove_photo" value="1" @checked(old('remove_photo'))>
                Remove photo
            </label>
        @endif
        <p class="help">A new photo replaces the current one. Leave it empty to keep the photo.</p>
        <button type="submit">Save dish</button>
    </form>
@endsection
