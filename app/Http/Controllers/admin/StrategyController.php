<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StrategyController extends Controller
{
    public function index()
    {
        $strategies = Strategy::with('rules')->orderByDesc('id')->get();

        return view('Backend.strategies.index', compact('strategies'));
    }

    public function create()
    {
        return view('Backend.strategies.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'risk_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stop_loss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'rules' => ['nullable', 'array'],
            'rules.*.type' => ['required', 'in:entry,exit'],
            'rules.*.indicator' => ['required', 'string', 'max:50'],
            'rules.*.parameters' => ['nullable', 'array'],
            'rules.*.operator' => ['required', 'string', 'max:30'],
            'rules.*.value_type' => ['required', 'in:number,indicator'],
            'rules.*.value' => ['nullable'],
            'rules.*.logical_operator' => ['nullable', 'in:AND,OR'],
            'rules.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $strategy = Strategy::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'risk_percent' => $data['risk_percent'] ?? null,
                'stop_loss' => $data['stop_loss'] ?? null,
                'take_profit' => $data['take_profit'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ]);

            foreach ($data['rules'] ?? [] as $rule) {
                $value = $rule['value'] ?? null;

                if ($rule['value_type'] === 'number' && $value !== null && $value !== '') {
                    $value = (float) $value;
                }

                $strategy->rules()->create([
                    'type' => $rule['type'],
                    'indicator' => $rule['indicator'],
                    'parameters' => $rule['parameters'] ?? [],
                    'operator' => $rule['operator'],
                    'value_type' => $rule['value_type'],
                    'value' => $value,
                    'logical_operator' => $rule['logical_operator'] ?? null,
                    'sort_order' => $rule['sort_order'] ?? 0,
                ]);
            }
        });

        return redirect()
            ->route('strategy.index')
            ->with('success', 'استراتژی با موفقیت ایجاد شد.');
    }
}
