@extends('layouts.staff')
@php
    $optionText = function (array $group): string {
        return collect($group['options'] ?? [])->map(function (array $option): string {
            return $option['name'].' | '.number_format(((int) $option['price_cents']) / 100, 2, '.', '');
        })->implode("\n");
    };
@endphp
@section('content')
    <a class="back" href="{{ route('menu.index') }}">Back to menu</a>
    <header class="page">
        <div>
            <p class="kicker">Café menu</p>
            <h1>{{ $item->exists ? $item->name : 'New menu item' }}</h1>
            <p class="lede">The shelf price is before tax. Required options are asked in WhatsApp before the customer confirms.</p>
        </div>
    </header>
    <div class="menu-editor">
        <form id="menu-item-form" method="post" action="{{ $item->exists ? route('menu.update', $item) : route('menu.store') }}">
            @csrf
            @if ($item->exists) @method('PUT') @endif
            <section class="card menu-panel">
                <h2>Item</h2>
                <label>Name
                    <input name="name" value="{{ old('name', $item->name) }}" required maxlength="120" placeholder="Breakfast sandwich" autocomplete="off">
                </label>
                <div class="menu-money">
                    <label>Price
                        <span class="money-field">
                            <span aria-hidden="true">$</span>
                            <input name="price" type="number" inputmode="decimal" step="0.01" min="0" max="999.99" value="{{ old('price', $item->exists ? number_format($item->price_cents / 100, 2, '.', '') : '') }}" required placeholder="0.00" aria-label="Price in dollars">
                        </span>
                    </label>
                    <label>Tax
                        <span class="money-field">
                            <input name="tax_percent" type="number" inputmode="decimal" step="0.01" min="0" max="30" value="{{ old('tax_percent', number_format(($item->tax_rate_bps ?? 500) / 100, 2, '.', '')) }}" required aria-label="Tax percent">
                            <span aria-hidden="true">%</span>
                        </span>
                    </label>
                </div>
                <label class="choice menu-available">
                    <input type="checkbox" name="available" value="1" @checked($errors->any() ? old('available') : ($item->available ?? true))>
                    <span>
                        <strong>Available to order</strong>
                        <em>Customers can ask for this on WhatsApp. Clear it when the item is sold out.</em>
                    </span>
                </label>
            </section>
            <section class="card menu-panel">
                <h2>Options</h2>
                <p class="help">Leave a group empty when the item has no extras. Write one option per line: the name, a bar, then the extra price.</p>
                @foreach ($groups as $index => $group)
                    <fieldset class="option-card">
                        <legend>Option group {{ $index + 1 }}</legend>
                        <div class="option-head">
                            <label>Group name
                                <input name="groups[{{ $index }}][name]" value="{{ old("groups.$index.name", $group['name'] ?? '') }}" placeholder="Size">
                            </label>
                            <label class="choice"><input type="checkbox" name="groups[{{ $index }}][required]" value="1" @checked($errors->any() ? old("groups.$index.required") : ($group['required'] ?? false))> Required</label>
                        </div>
                        <label>Options
                            <textarea name="groups[{{ $index }}][options]" rows="4" placeholder="Small | 0.00&#10;Large | 1.00">{{ old("groups.$index.options", $optionText($group)) }}</textarea>
                        </label>
                    </fieldset>
                @endforeach
            </section>
            <div class="menu-actions">
                <button type="submit">{{ $item->exists ? 'Save item' : 'Add item' }}</button>
                <a class="button quiet" href="{{ route('menu.index') }}">Cancel</a>
            </div>
        </form>
        <aside class="menu-preview">
            <p class="kicker">On the menu</p>
            <article class="preview-card">
                <span class="pill good" id="preview-status">Available</span>
                <h2 id="preview-name">New item</h2>
                <p class="preview-price"><strong id="preview-price">—</strong></p>
                <p class="muted" id="preview-tax">before tax</p>
                <div id="preview-groups"><p class="empty">No options yet.</p></div>
            </article>
            <p class="muted">WhatsApp adds the tax when it quotes the order.</p>
        </aside>
    </div>
    <script>
        (function () {
            var form = document.getElementById('menu-item-form');
            if (!form) return;
            var name = form.querySelector('[name="name"]');
            var price = form.querySelector('[name="price"]');
            var tax = form.querySelector('[name="tax_percent"]');
            var available = form.querySelector('[name="available"]');

            function escapeText(value) {
                return value.replace(/[&<>"]/g, function (character) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[character];
                });
            }

            function money(value) {
                var amount = Number(value);
                return Number.isFinite(amount) ? '$' + amount.toFixed(2) : '—';
            }

            function render() {
                document.getElementById('preview-name').textContent = name.value.trim() || '{{ $item->exists ? 'Menu item' : 'New item' }}';
                document.getElementById('preview-price').textContent = price.value === '' ? '—' : money(price.value);
                var taxAmount = Number(tax.value);
                document.getElementById('preview-tax').textContent = Number.isFinite(taxAmount)
                    ? 'before ' + taxAmount.toFixed(2).replace(/\.00$/, '') + '% tax'
                    : 'before tax';
                var status = document.getElementById('preview-status');
                status.textContent = available.checked ? 'Available' : 'Sold out';
                status.className = 'pill ' + (available.checked ? 'good' : 'warn');

                var blocks = Array.prototype.map.call(form.querySelectorAll('.option-card'), function (card) {
                    var groupName = (card.querySelector('input[name$="[name]"]').value || '').trim();
                    var lines = (card.querySelector('textarea').value || '').split(/\n/).map(function (line) {
                        return line.trim();
                    }).filter(Boolean);
                    if (!groupName && lines.length === 0) return '';
                    var required = card.querySelector('input[type="checkbox"]').checked;
                    var items = lines.map(function (line) {
                        var parts = line.split('|');
                        var label = (parts[0] || '').trim() || 'Option';
                        var extra = Number((parts[1] || '').trim());
                        var extraText = Number.isFinite(extra) && extra > 0 ? ' +$' + extra.toFixed(2) : '';
                        return '<li>' + escapeText(label) + extraText + '</li>';
                    }).join('');
                    return '<section><h3>' + escapeText(groupName || 'Options') + (required ? ' · required' : '') + '</h3><ul>' + items + '</ul></section>';
                }).join('');
                document.getElementById('preview-groups').innerHTML = blocks || '<p class="empty">No options yet.</p>';
            }

            form.addEventListener('input', render);
            form.addEventListener('change', render);
            render();
        })();
    </script>
@endsection
