@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3>{{ $trade ? 'ویرایش معامله #'.$trade->id : 'ایجاد معامله' }}</h3>
            </div>

            <div class="panel-body">
                @if($markets->isEmpty())
                    <div class="alert alert-warning">
                        هنوز هیچ منبع بازاری فعال نیست یا نمادهای آن همگام‌سازی نشده‌اند.
                        ابتدا از بخش بازارها، نمادها را بروزرسانی کنید.
                    </div>
                @endif

                <form action="{{ $trade ? route('trade.update', $trade) : route('trade.store') }}" method="post">
                    @csrf
                    @if($trade)
                        @method('PUT')
                    @endif
                    @csrf

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>استراتژی</label>
                            <select name="strategy_id" class="form-control" required>
                                <option value="">انتخاب استراتژی</option>
                                @foreach($strategies as $strategy)
                                    <option value="{{ $strategy->id }}" {{ $trade && $trade->strategy_id == $strategy->id ? 'selected' : '' }}>{{ $strategy->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>نوع معامله</label>
                            <select name="type" id="trade-type" class="form-control" required>
                                <option value="backtest" {{ !$trade || $trade->type === 'backtest' ? 'selected' : '' }}>Backtest</option>
                                <option value="paper" {{ $trade && $trade->type === 'paper' ? 'selected' : '' }}>Paper</option>
                                <option value="live" {{ $trade && $trade->type === 'live' ? 'selected' : '' }}>Live</option>
                            </select>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>منبع بازار</label>
                            <select name="market_id" id="trade-market" class="form-control" required>
                                <option value="">انتخاب منبع</option>
                                @foreach($markets as $market)
                                    <option value="{{ $market->id }}" data-symbol-count="{{ $market->symbols->count() }}" {{ $trade && $trade->market_id == $market->id ? 'selected' : '' }}>
                                        {{ $market->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>نماد</label>
                            <select name="market_symbol_id" id="trade-symbol" class="form-control" required disabled>
                                <option value="">ابتدا منبع بازار را انتخاب کنید</option>
                                @foreach($markets as $market)
                                    @foreach($market->symbols as $symbol)
                                        <option
                                            value="{{ $symbol->id }}"
                                            data-market-id="{{ $market->id }}"
                                            data-symbol="{{ $symbol->symbol }}"
                                            style="display:none;"
                                            {{ $trade && $trade->market_symbol_id == $symbol->id ? 'selected' : '' }}
                                        >
                                            {{ $symbol->display_name }}
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                            <small id="trade-symbol-help" class="help-block">
                                نمادهای فعال منبع انتخاب‌شده نمایش داده می‌شوند.
                            </small>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>تایم‌فریم</label>
                            <select name="timeframe" class="form-control" required>
                                <option value="1m" {{ $trade && $trade->timeframe === '1m' ? 'selected' : '' }}>1m</option>
                                <option value="5m" {{ $trade && $trade->timeframe === '5m' ? 'selected' : '' }}>5m</option>
                                <option value="15m" {{ !$trade || $trade->timeframe === '15m' ? 'selected' : '' }}>15m</option>
                                <option value="30m" {{ $trade && $trade->timeframe === '30m' ? 'selected' : '' }}>30m</option>
                                <option value="1h" {{ $trade && $trade->timeframe === '1h' ? 'selected' : '' }}>1h</option>
                                <option value="4h" {{ $trade && $trade->timeframe === '4h' ? 'selected' : '' }}>4h</option>
                                <option value="1d" {{ $trade && $trade->timeframe === '1d' ? 'selected' : '' }}>1d</option>
                            </select>
                        </div>

                        <div class="col-md-4 form-group">
                            <label>سرمایه اولیه</label>
                            <input type="number" name="initial_capital" class="form-control" step="0.00000001" min="0.00000001" value="{{ $trade->initial_capital ?? '' }}" required>
                        </div>

                        <div class="col-md-4 form-group">
                            <label>کارمزد (%)</label>
                            <input type="number" name="fee_percent" class="form-control" step="0.0001" min="0" value="{{ $trade->fee_percent ?? '0.1' }}">
                        </div>
                    </div>

                    <div id="backtest-fields" class="panel panel-default" style="margin-top: 15px;">
                        <div class="panel-heading">بازه بک‌تست</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label>از تاریخ</label>
                                    <input type="datetime-local" name="start_date" class="form-control" value="{{ $trade && $trade->start_date ? $trade->start_date->format('Y-m-d\\TH:i') : '' }}">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>تا تاریخ</label>
                                    <input type="datetime-local" name="end_date" class="form-control" value="{{ $trade && $trade->end_date ? $trade->end_date->format('Y-m-d\\TH:i') : '' }}">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>Warm-up (تعداد کندل)</label>
                                    <input type="number" name="warmup_candles" class="form-control" min="1" max="100000" step="1"
                                           value="{{ old('warmup_candles', $trade->warmup_candles ?? 1000) }}" required>
                                    <small class="help-block">فقط برای آماده‌سازی اندیکاتورهاست و در بازه معامله محسوب نمی‌شود.</small>
                                </div>
                            </div>                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success" {{ $markets->isEmpty() ? 'disabled' : '' }}>ایجاد معامله</button>
                    <a href="{{ route('trade.index') }}" class="btn btn-default">انصراف</a>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var type = document.getElementById('trade-type');
    var fields = document.getElementById('backtest-fields');
    var market = document.getElementById('trade-market');
    var symbol = document.getElementById('trade-symbol');
    var help = document.getElementById('trade-symbol-help');

    function toggleBacktestFields() {
        fields.style.display = type.value === 'backtest' ? '' : 'none';
    }

    function updateSymbols() {
        var marketId = market.value;
        var options = symbol.querySelectorAll('option[data-market-id]');
        var visibleCount = 0;

        symbol.value = '';

        options.forEach(function (option) {
            var visible = option.getAttribute('data-market-id') === marketId;
            option.style.display = visible ? '' : 'none';
            option.disabled = !visible;

            if (visible) {
                visibleCount++;
            }
        });

        symbol.disabled = !marketId || visibleCount === 0;

        if (!marketId) {
            help.textContent = 'ابتدا منبع بازار را انتخاب کنید.';
        } else if (visibleCount === 0) {
            help.textContent = 'برای این منبع هنوز نماد فعالی ثبت نشده است.';
        } else {
            help.textContent = visibleCount + ' نماد فعال در این منبع موجود است.';
        }
    }

    type.addEventListener('change', toggleBacktestFields);
    market.addEventListener('change', updateSymbols);

    toggleBacktestFields();
    updateSymbols();
    @if($trade)
        symbol.value = '{{ $trade->market_symbol_id }}';
        updateSymbols();
        symbol.value = '{{ $trade->market_symbol_id }}';
    @endif
})();
</script>
@endsection
