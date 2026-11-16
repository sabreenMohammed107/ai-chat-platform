<div class="row" style="align-items:flex-end">
    <div class="grow"><label>{{ __('Name') }}</label><input type="text" name="name" value="{{ $plan?->name }}" required></div>
    <div><label>{{ __('Messages a month') }}</label><input type="number" name="monthly_messages" value="{{ $plan?->monthly_messages ?? 1000 }}" min="1" required></div>
    <div><label>{{ __('Price a month') }}</label><input type="number" name="price" value="{{ $plan?->price ?? 0 }}" min="0" step="0.01" required></div>
    <div><label>{{ __('Website pages') }}</label><input type="number" name="max_pages" value="{{ $plan?->max_pages ?? 100 }}" min="1" required></div>
    <div><label>{{ __('Model') }}</label>
        <select name="model">
            <option value="">{{ __('Default') }}</option>
            @foreach ($models as $m)<option value="{{ $m }}" @selected($plan?->model === $m)>{{ $m }}</option>@endforeach
        </select>
    </div>
</div>
