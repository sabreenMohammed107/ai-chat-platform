<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return view('admin.plans', [
            'plans' => Plan::withCount('subscriptions')->orderBy('price')->get(),
            'models' => array_keys((array) config('chat.prices')),
        ]);
    }

    public function store(Request $request)
    {
        Plan::create($this->validated($request));

        return back()->with('status', __('Saved.'));
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($this->validated($request));

        return back()->with('status', __('Saved.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'monthly_messages' => ['required', 'integer', 'min:1', 'max:10000000'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'model' => ['nullable', 'string', 'in:'.implode(',', array_keys((array) config('chat.prices')))],
            'max_pages' => ['required', 'integer', 'min:1', 'max:5000'],
        ]);
    }
}
