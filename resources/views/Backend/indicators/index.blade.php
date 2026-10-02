@extends('Backend.layouts.Master')

@section('content')
<style>
    .indicator-lab .panel { border-radius: 4px; }
    .indicator-lab .lab-header { margin-bottom: 20px; }
    .indicator-lab .lab-header h2 { margin: 0 0 7px; font-size: 24px; }
    .indicator-lab .lab-header p { margin: 0; color: #777; }
    .indicator-lab .field-label { display: block; margin-bottom: 7px; font-weight: 600; }
    .indicator-lab .form-control { height: 38px; }
    .indicator-lab .chart-panel { margin-top: 20px; }
    .indicator-lab .chart-wrap { position: relative; width: 100%; height: 430px; overflow: hidden; background: #fff; border: 1px solid #eee; }
    .indicator-lab .chart-svg { display: block; width: 100%; height: 100%; }
    .indicator-lab .chart-axis { stroke: #ddd; stroke-width: 1; }
    .indicator-lab .chart-close { fill: none; stroke: #777; stroke-width: 1.5; vector-effect: non-scaling-stroke; }
    .indicator-lab .chart-ema { fill: none; stroke: #c79a3b; stroke-width: 2.5; vector-effect: non-scaling-stroke; }
    .indicator-lab .chart-rsi { fill: none; stroke: #c79a3b; stroke-width: 2.5; vector-effect: non-scaling-stroke; }
    .indicator-lab .chart-rsi-level { stroke: #ddd; stroke-width: 1; stroke-dasharray: 6 5; }
    .indicator-lab .chart-macd { fill: none; stroke: #c79a3b; stroke-width: 2.5; vector-effect: non-scaling-stroke; }
    .indicator-lab .chart-signal { fill: none; stroke: #777; stroke-width: 2; vector-effect: non-scaling-stroke; }
    .indicator-lab .chart-zero { stroke: #ddd; stroke-width: 1; stroke-dasharray: 6 5; }
    .indicator-lab .chart-histogram { fill: #c79a3b; opacity: .35; }
    .indicator-lab .chart-legend { margin-top: 10px; color: #666; }
    .indicator-lab .legend-item { display: inline-block; margin-left: 18px; }
    .indicator-lab .legend-line { display: inline-block; width: 24px; height: 2px; vertical-align: middle; margin-left: 6px; background: #777; }
    .indicator-lab .legend-line.ema { height: 3px; background: #c79a3b; }
    .indicator-lab .stats { margin-top: 15px; }
    .indicator-lab .stat { display: inline-block; min-width: 150px; padding: 12px 16px; margin-left: 10px; margin-bottom: 10px; border: 1px solid #eee; background: #fafafa; }
    .indicator-lab .stat small { display: block; color: #888; margin-bottom: 4px; }
    .indicator-lab .table-wrap { max-height: 500px; overflow: auto; }
    .indicator-lab table { margin-bottom: 0; }
    .indicator-lab .ema-value { font-weight: 600; }
    .indicator-lab .muted-note { color: #888; margin-top: 8px; }
</style>

<div class="row indicator-lab">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        <div class="lab-header">
            <h2>آزمایشگاه اندیکاتورها</h2>
            <p>محاسبه و بررسی اندیکاتورها روی داده‌های ذخیره‌شده در market_candles</p>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>تنظیم تست</strong></div>

            <div class="panel-body">
                <form method="post" action="{{ route('indicator-lab.test') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-2">
                            <label class="field-label">اندیکاتور</label>
                            <select name="indicator" id="indicator-type" class="form-control">
                                <option value="ema" {{ old('indicator', $result['indicator'] ?? 'ema') === 'ema' ? 'selected' : '' }}>EMA</option>
                                <option value="rsi" {{ old('indicator', $result['indicator'] ?? 'ema') === 'rsi' ? 'selected' : '' }}>RSI</option>
                                <option value="macd" {{ old('indicator', $result['indicator'] ?? 'ema') === 'macd' ? 'selected' : '' }}>MACD</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="field-label">بازار</label>
                            <select name="market_id" id="indicator-market" class="form-control" required>
                                @foreach($markets as $market)
                                    <option value="{{ $market->id }}"
                                        {{ (string) old('market_id', $result['market_id'] ?? ($markets->first()->id ?? '')) === (string) $market->id ? 'selected' : '' }}>
                                        {{ $market->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="field-label">نماد</label>
                            <select name="market_symbol_id" id="indicator-symbol" class="form-control" required>
                                @foreach($markets as $market)
                                    @foreach($market->symbols as $symbol)
                                        <option value="{{ $symbol->id }}"
                                            data-market="{{ $market->id }}"
                                            {{ (string) old('market_symbol_id', $result['market_symbol_id'] ?? '') === (string) $symbol->id ? 'selected' : '' }}>
                                            {{ $symbol->display_name ?: $symbol->symbol }}
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-1" id="standard-period-field">
                            <label class="field-label">Period</label>
                            <input type="number" name="period" class="form-control"
                                   value="{{ old('period', $result['period'] ?? 14) }}"
                                   min="1" max="1000" required>
                        </div>
                        <div class="col-md-1 macd-field" style="display:none;">
                            <label class="field-label">Fast</label>
                            <input type="number" name="macd_fast" class="form-control" value="{{ old('macd_fast', $result['macd_fast'] ?? 12) }}" min="1" max="1000">
                        </div>
                        <div class="col-md-1 macd-field" style="display:none;">
                            <label class="field-label">Slow</label>
                            <input type="number" name="macd_slow" class="form-control" value="{{ old('macd_slow', $result['macd_slow'] ?? 26) }}" min="2" max="1000">
                        </div>
                        <div class="col-md-1 macd-field" style="display:none;">
                            <label class="field-label">Signal</label>
                            <input type="number" name="macd_signal" class="form-control" value="{{ old('macd_signal', $result['macd_signal'] ?? 9) }}" min="1" max="1000">
                        </div>

                        <div class="col-md-2">
                            <label class="field-label">تایم‌فریم</label>
                            <select name="timeframe" class="form-control" required>
                                @foreach(['1m','5m','15m','30m','1h','4h','1d'] as $timeframe)
                                    <option value="{{ $timeframe }}"
                                        {{ old('timeframe', $result['timeframe'] ?? '1h') === $timeframe ? 'selected' : '' }}>
                                        {{ $timeframe }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="field-label">Warm-up (کندل)</label>
                            <input type="number" name="warmup_candles" class="form-control"
                                   value="{{ old('warmup_candles', $result['warmup_setting'] ?? 1000) }}"
                                   min="1" max="100000" step="1" required>
                        </div>
                    </div>

                    <div class="row" style="margin-top:15px;">
                        <div class="col-md-4">
                            <label class="field-label">از تاریخ</label>
                            <input type="datetime-local" name="start_date" class="form-control"
                                   value="{{ old('start_date', $result['start_date'] ?? $default_start_date) }}" required>
                        </div>

                        <div class="col-md-4">
                            <label class="field-label">تا تاریخ</label>
                            <input type="datetime-local" name="end_date" class="form-control"
                                   value="{{ old('end_date', $result['end_date'] ?? $default_end_date) }}" required>
                        </div>

                        <div class="col-md-4" style="padding-top:24px;">
                            <button type="submit" class="btn btn-success">▶ اجرای اندیکاتور</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if($result)
            <div class="panel panel-default chart-panel">
                <div class="panel-heading clearfix">
                    <strong>نتیجه {{ strtoupper($result['indicator']) }} {{ $result['period'] }}</strong>
                    <span class="pull-left">{{ $result['symbol'] }} / {{ $result['timeframe'] }}</span>
                </div>

                <div class="panel-body">
                    <div class="chart-wrap">
                        <svg id="ema-chart" class="chart-svg" viewBox="0 0 1200 430" preserveAspectRatio="none" aria-label="نمودار اندیکاتور"></svg>
                    </div>

                    <div class="chart-legend">
                        @if(($result['indicator'] ?? 'ema') === 'ema')
                            <span class="legend-item"><span class="legend-line"></span> Close</span>
                            <span class="legend-item"><span class="legend-line ema"></span> EMA {{ $result['period'] }}</span>
                        @elseif(($result['indicator'] ?? 'ema') === 'macd')
                            <span class="legend-item"><span class="legend-line ema"></span> MACD {{ $result['macd_fast'] }}/{{ $result['macd_slow'] }}</span>
                            <span class="legend-item"><span class="legend-line"></span> Signal {{ $result['macd_signal'] }}</span>
                            <span class="legend-item">Histogram</span>
                        @else
                            <span class="legend-item"><span class="legend-line ema"></span> RSI {{ $result['period'] }}</span>
                        @endif
                    </div>

                    <div class="stats">
                        <div class="stat"><small>کندل‌های Warm-up</small><strong>{{ number_format($result['warmup_count']) }}</strong></div>
                        <div class="stat"><small>Warm-up درخواستی</small><strong>{{ number_format($result['warmup_setting']) }}</strong></div>
                        <div class="stat"><small>کل کندل‌های محاسبات</small><strong>{{ number_format($result['candle_count']) }}</strong></div>
                        <div class="stat"><small>کندل‌های خروجی</small><strong>{{ number_format($result['displayed_count']) }}</strong></div>
                        <div class="stat"><small>کندل آماده اندیکاتور</small><strong>{{ number_format($result['ready_count']) }}</strong></div>
                        <div class="stat"><small>اولین EMA</small><strong>{{ $result['first_ready_time'] ?: '—' }}</strong></div>
                        <div class="stat"><small>آخرین EMA</small><strong>{{ $result['last_ready_time'] ?: '—' }}</strong></div>
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading clearfix">
                    <strong>خروجی محاسبات</strong>
                    <span class="pull-left">۲۰۰ کندل آخر</span>
                </div>

                <div class="panel-body">
                    <div class="table-responsive table-wrap">
                        <table class="table table-striped table-hover">
                            <thead>
                            <tr>
                                <th>زمان</th>
                                @if(($result['indicator'] ?? 'ema') === 'macd')
                                    <th>MACD</th><th>Signal</th><th>Histogram</th>
                                @else
                                    <th>Close</th><th>{{ strtoupper($result['indicator']) }} {{ $result['period'] }}</th>
                                @endif
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($result['table_points'] as $point)
                                <tr>
                                    <td>{{ $point['time'] }}</td>
                                    @if(($result['indicator'] ?? 'ema') === 'macd')
                                        <td class="ema-value">{{ number_format($point['macd'], 4) }}</td>
                                        <td>{{ number_format($point['signal'], 4) }}</td>
                                        <td>{{ number_format($point['histogram'], 4) }}</td>
                                    @else
                                        <td>{{ number_format($point['close'], 2) }}</td>
                                        <td class="ema-value">{{ number_format($point['value'], 4) }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="{{ ($result['indicator'] ?? 'ema') === 'macd' ? 4 : 3 }}" class="text-center">برای این بازه نقطه‌ی قابل نمایش برای اندیکاتور وجود ندارد.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="muted-note">Warm-up در محاسبه اندیکاتور استفاده می‌شود ولی در نمودار و جدول نمایش داده نمی‌شود.</p>
                </div>
            </div>
        @endif
    </div>
</div>

@if($result)
<script>
(function () {
    var points = @json($result['chart_points'] ?? []);
    var svg = document.getElementById('ema-chart');
    if (!svg || !points.length) return;

    var width = 1200, height = 430;
    var pad = {top: 20, right: 20, bottom: 25, left: 20};
    var isRsi = @json(($result['indicator'] ?? 'ema') === 'rsi');
    var isMacd = @json(($result['indicator'] ?? 'ema') === 'macd');
    var values = [];

    points.forEach(function (point) {
        if (isRsi) {
            if (point.value !== null) values.push(point.value);
        } else if (isMacd) {
            if (point.macd !== null) values.push(point.macd);
            if (point.signal !== null) values.push(point.signal);
            if (point.histogram !== null) values.push(point.histogram);
        } else {
            if (point.close !== null) values.push(point.close);
            if (point.value !== null) values.push(point.value);
        }
    });

    if (!values.length) return;

    var min = isRsi ? 0 : Math.min.apply(null, values);
    var max = isRsi ? 100 : Math.max.apply(null, values);

    if (isMacd) {
        var abs = Math.max(Math.abs(min), Math.abs(max), 1);
        min = -abs;
        max = abs;
    }

    var range = max - min || 1;

    function x(index) {
        return pad.left + (index / Math.max(points.length - 1, 1)) * (width - pad.left - pad.right);
    }
    function y(value) {
        return height - pad.bottom - ((value - min) / range) * (height - pad.top - pad.bottom);
    }
    function pathFor(key) {
        var path = '';
        points.forEach(function (point, index) {
            var value = point[key];
            if (value === null || value === undefined) return;
            path += (path ? ' L ' : 'M ') + x(index).toFixed(2) + ' ' + y(value).toFixed(2);
        });
        return path;
    }

    var grid = '';
    for (var i = 1; i <= 4; i++) {
        var gy = pad.top + ((height - pad.top - pad.bottom) * i / 5);
        grid += '<line class="chart-axis" x1="' + pad.left + '" y1="' + gy + '" x2="' + (width - pad.right) + '" y2="' + gy + '"></line>';
    }

    var levels = '';
    if (isRsi) {
        [30, 70].forEach(function (level) {
            levels += '<line class="chart-rsi-level" x1="' + pad.left + '" y1="' + y(level) + '" x2="' + (width - pad.right) + '" y2="' + y(level) + '"></line>';
        });
    }

    if (isMacd) {
        levels += '<line class="chart-zero" x1="' + pad.left + '" y1="' + y(0) + '" x2="' + (width - pad.right) + '" y2="' + y(0) + '"></line>';
        var bars = '';
        points.forEach(function (point, index) {
            if (point.histogram === null || point.histogram === undefined) return;
            var barWidth = Math.max(1, ((width - pad.left - pad.right) / points.length) * 0.7);
            var barX = x(index) - barWidth / 2;
            var zeroY = y(0);
            var barY = point.histogram >= 0 ? y(point.histogram) : zeroY;
            var barHeight = Math.abs(y(point.histogram) - zeroY);
            bars += '<rect class="chart-histogram" x="' + barX.toFixed(2) + '" y="' + barY.toFixed(2) + '" width="' + barWidth.toFixed(2) + '" height="' + Math.max(1, barHeight).toFixed(2) + '"></rect>';
        });
        svg.innerHTML = grid + levels + bars +
            '<path class="chart-macd" d="' + pathFor('macd') + '"></path>' +
            '<path class="chart-signal" d="' + pathFor('signal') + '"></path>';
        return;
    }

    svg.innerHTML = grid + levels +
        (isRsi ? '' : '<path class="chart-close" d="' + pathFor('close') + '"></path>') +
        '<path class="chart-ema" d="' + pathFor('value') + '"></path>';
})();
</script>
@endif

<script>
(function () {
    var indicator = document.getElementById('indicator-type');
    var standard = document.getElementById('standard-period-field');
    var macdFields = document.querySelectorAll('.macd-field');
    if (!indicator) return;

    function updateIndicatorFields() {
        var isMacd = indicator.value === 'macd';
        if (standard) standard.style.display = isMacd ? 'none' : '';
        Array.prototype.forEach.call(macdFields, function (field) {
            field.style.display = isMacd ? '' : 'none';
        });
    }

    indicator.addEventListener('change', updateIndicatorFields);
    updateIndicatorFields();
})();
</script>

<script>
(function () {
    var market = document.getElementById('indicator-market');
    var symbol = document.getElementById('indicator-symbol');
    if (!market || !symbol) return;

    function filterSymbols() {
        var selectedMarket = market.value;
        var firstVisible = null;

        Array.prototype.forEach.call(symbol.options, function (option) {
            var visible = option.getAttribute('data-market') === selectedMarket;
            option.hidden = !visible;
            if (visible && !firstVisible) firstVisible = option;
        });

        if (!symbol.selectedOptions.length ||
            symbol.selectedOptions[0].getAttribute('data-market') !== selectedMarket) {
            if (firstVisible) symbol.value = firstVisible.value;
        }
    }

    market.addEventListener('change', filterSymbols);
    filterSymbols();
})();
</script>
@endsection
