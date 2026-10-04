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
        $strategies = Strategy::with(['rules', 'trades'])->orderByDesc('id')->get();

        return view('Backend.strategies.index', compact('strategies'));
    }

    public function create(Request $request)
    {
        $type = $request->input('type');

        if ($type === 'ma_trend') {
            return view('Backend.strategies.ma-trend', ['strategy' => null]);
        }

        if ($type === 'generic') {
            return view('Backend.strategies.create', ['strategy' => null]);
        }

        return view('Backend.strategies.choose');
    }

    public function edit(Strategy $strategy)
    {
        $strategy->load('rules');

        if ($strategy->strategy_type === 'ma_trend') {
            return view('Backend.strategies.ma-trend', compact('strategy'));
        }

        return view('Backend.strategies.create', compact('strategy'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'strategy_type' => ['required', 'in:generic,ma_trend'],
            'direction' => ['required', 'in:long,short,both'],
            'risk_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stop_loss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'config' => ['nullable', 'array'],
            'config.ma_type' => ['nullable', 'in:ema'],
            'config.ma_period' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'config.pullback_zone_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'config.min_confirmation_candle_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'config.exit_sequence_count' => ['nullable', 'integer', 'min:2', 'max:20'],
            'rules' => ['nullable', 'array'],
            'rules.*.type' => ['required', 'in:entry,exit,long_entry,long_exit,short_entry,short_exit'],
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
                'strategy_type' => $data['strategy_type'],
                'config' => $data['config'] ?? null,
                'direction' => $data['direction'],
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

    public function update(Request $request, Strategy $strategy)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'strategy_type' => ['required', 'in:generic,ma_trend'],
            'direction' => ['required', 'in:long,short,both'],
            'config' => ['nullable', 'array'],
            'config.ma_type' => ['nullable', 'in:ema'],
            'config.ma_period' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'config.pullback_zone_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'config.min_confirmation_candle_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'config.exit_sequence_count' => ['nullable', 'integer', 'min:2', 'max:20'],
            'risk_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stop_loss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'rules' => ['nullable', 'array'],
            'rules.*.type' => ['required', 'in:entry,exit,long_entry,long_exit,short_entry,short_exit'],
            'rules.*.indicator' => ['required', 'string', 'max:50'],
            'rules.*.parameters' => ['nullable', 'array'],
            'rules.*.operator' => ['required', 'string', 'max:30'],
            'rules.*.value_type' => ['required', 'in:number,indicator'],
            'rules.*.value' => ['nullable'],
            'rules.*.logical_operator' => ['nullable', 'in:AND,OR'],
            'rules.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($strategy->trades()->where('status', '!=', 'draft')->exists()) {
            return back()->with('error', 'این استراتژی در یک معامله اجراشده یا در حال اجرا استفاده شده و قابل ویرایش نیست. برای تغییر آن، از داپلیکیت استفاده کنید.');
        }

        DB::transaction(function () use ($request, $data, $strategy) {
            $strategy->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'strategy_type' => $data['strategy_type'],
                'config' => $data['config'] ?? null,
                'direction' => $data['direction'],
                'risk_percent' => $data['risk_percent'] ?? null,
                'stop_loss' => $data['stop_loss'] ?? null,
                'take_profit' => $data['take_profit'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ]);

            $strategy->rules()->delete();

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
            ->with('success', 'استراتژی با موفقیت ویرایش شد.');
    }

    public function destroy(Strategy $strategy)
    {
        if ($strategy->trades()->exists()) {
            return redirect()
                ->route('strategy.index')
                ->with('error', 'این استراتژی به معامله متصل است و قابل حذف نیست. برای حفظ سابقه، ابتدا از آن داپلیکیت بگیرید و نسخه اصلی را غیرفعال کنید.');
        }

        $strategy->delete();

        return redirect()
            ->route('strategy.index')
            ->with('success', 'استراتژی با موفقیت حذف شد.');
    }

    public function duplicate(Strategy $strategy)
    {
        $strategy->load('rules');

        DB::transaction(function () use ($strategy) {
            $copy = Strategy::create([
                'name' => $strategy->name . ' - کپی',
                'description' => $strategy->description,
                'strategy_type' => $strategy->strategy_type,
                'config' => $strategy->config,
                'direction' => $strategy->direction,
                'risk_percent' => $strategy->risk_percent,
                'stop_loss' => $strategy->stop_loss,
                'take_profit' => $strategy->take_profit,
                'is_active' => $strategy->is_active,
            ]);

            foreach ($strategy->rules as $rule) {
                $copy->rules()->create([
                    'type' => $rule->type,
                    'indicator' => $rule->indicator,
                    'parameters' => $rule->parameters,
                    'operator' => $rule->operator,
                    'value_type' => $rule->value_type,
                    'value' => $rule->value,
                    'logical_operator' => $rule->logical_operator,
                    'sort_order' => $rule->sort_order,
                ]);
            }
        });

        return redirect()
            ->route('strategy.index')
            ->with('success', 'استراتژی با موفقیت داپلیکیت شد.');
    }
}
