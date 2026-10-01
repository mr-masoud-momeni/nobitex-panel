<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\Request;

class StrategyController extends Controller
{
    public function index()
    {
        $strategies = Strategy::orderByDesc('id')->get();

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
            'entry_conditions' => ['nullable', 'string'],
            'exit_conditions' => ['nullable', 'string'],
            'risk_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stop_loss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        Strategy::create($data);

        return redirect()
            ->route('strategy.index')
            ->with('success', 'استراتژی با موفقیت ایجاد شد.');
    }
}
