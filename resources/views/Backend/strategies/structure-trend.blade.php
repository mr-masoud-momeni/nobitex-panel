@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-10 col-lg-offset-1">
        @include('Backend.layouts.errors')
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3>{{ $strategy ? 'ویرایش استراتژی روند ساختاری' : 'ایجاد استراتژی روند ساختاری' }}</h3>
            </div>
            <div class="panel-body">
                <form action="{{ $strategy ? route('strategy.update', $strategy) : route('strategy.store') }}" method="post">
                    @csrf
                    <input type="hidden" name="strategy_type" value="structure_trend">

                    <div class="form-group">
                        <label>نام استراتژی</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $strategy->name ?? '') }}" required>
                    </div>

                    <div class="form-group">
                        <label>توضیحات</label>
                        <textarea name="description" class="form-control" rows="3">{{ old('description', $strategy->description ?? '') }}</textarea>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">ساختار و پولبک</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>تعداد کندل تشخیص Sideways</label>
                                    <input type="number" name="config[range_lookback_candles]" class="form-control" min="10" max="500" step="1"
                                           value="{{ old('config.range_lookback_candles', $strategy->config['range_lookback_candles'] ?? 30) }}" required>
                                    <small class="text-muted">تعداد کندل‌هایی که برای تشخیص یک محدوده خنثی بررسی می‌شوند.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>حداقل کندل حرکت بعد از Breakout</label>
                                    <input type="number" name="config[min_move_candles]" class="form-control" min="1" max="20" step="1"
                                           value="{{ old('config.min_move_candles', $strategy->config['min_move_candles'] ?? 2) }}" required>
                                    <small class="text-muted">قبل از شروع پولبک باید حداقل این تعداد کندل در جهت شکست حرکت کنند.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>حداقل کندل Pullback</label>
                                    <input type="number" name="config[min_pullback_candles]" class="form-control" min="1" max="20" step="1"
                                           value="{{ old('config.min_pullback_candles', $strategy->config['min_pullback_candles'] ?? 1) }}" required>
                                    <small class="text-muted">پولبک می‌تواند فقط یک کندل باشد؛ برای روندهای قوی این مقدار مناسب است.</small>
                                </div>
                            </div>

                            <div class="row" style="margin-top:15px;">
                                <div class="col-md-4">
                                    <label>حداقل اندازه کندل ورود (%)</label>
                                    <input type="number" name="config[min_entry_candle_percent]" class="form-control" min="0" max="100" step="0.01"
                                           value="{{ old('config.min_entry_candle_percent', $strategy->config['min_entry_candle_percent'] ?? 0.3) }}" required>
                                    <small class="text-muted">اندازه بدنه کندل ورود؛ جلوی ورود با کندل بسیار ضعیف را می‌گیرد.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>حداقل فاصله قیمت از EMA (%)</label>
                                    <input type="number" name="config[min_ema_distance_percent]" class="form-control" min="0" max="100" step="0.01"
                                           value="{{ old('config.min_ema_distance_percent', $strategy->config['min_ema_distance_percent'] ?? 0.2) }}" required>
                                    <small class="text-muted">کندل ورود باید حداقل این فاصله را از EMA داشته باشد.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>دوره EMA</label>
                                    <input type="number" name="config[ma_period]" class="form-control" min="1" max="1000" step="1"
                                           value="{{ old('config.ma_period', $strategy->config['ma_period'] ?? 20) }}" required>
                                    <small class="text-muted">EMA فقط فیلتر فاصله است و روند را تشخیص نمی‌دهد.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">جهت و مدیریت معامله</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <label>جهت معامله</label>
                                    <select name="direction" class="form-control" required>
                                        <option value="long" {{ old('direction', $strategy->direction ?? 'long') === 'long' ? 'selected' : '' }}>Long</option>
                                        <option value="short" {{ old('direction', $strategy->direction ?? 'long') === 'short' ? 'selected' : '' }}>Short</option>
                                        <option value="both" {{ old('direction', $strategy->direction ?? 'long') === 'both' ? 'selected' : '' }}>Both</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label>ریسک هر معامله (%)</label>
                                    <input type="number" name="risk_percent" class="form-control" step="0.001" min="0" max="100"
                                           value="{{ old('risk_percent', $strategy->risk_percent ?? '') }}">
                                </div>
                                <div class="col-md-3">
                                    <label>حد ضرر (%)</label>
                                    <input type="number" name="stop_loss" class="form-control" step="0.001" min="0" max="100"
                                           value="{{ old('stop_loss', $strategy->stop_loss ?? '') }}">
                                </div>
                                <div class="col-md-3">
                                    <label>حد سود (%)</label>
                                    <input type="number" name="take_profit" class="form-control" step="0.001" min="0"
                                           value="{{ old('take_profit', $strategy->take_profit ?? '') }}">
                                </div>
                            </div>

                            <div class="row" style="margin-top:15px;">
                                <div class="col-md-6">
                                    <label>تعداد کندل خروج</label>
                                    <input type="number" name="config[exit_sequence_count]" class="form-control" min="2" max="20" step="1"
                                           value="{{ old('config.exit_sequence_count', $strategy->config['exit_sequence_count'] ?? 3) }}" required>
                                    <small class="text-muted">بعد از هر Close جدید، شمارش از صفر شروع می‌شود؛ اگر این تعداد Close جدید ثبت نشود، خروج انجام می‌شود.</small>
                                </div>
                            </div>

                            <div style="margin-top:15px;">
                                <label>
                                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $strategy ? $strategy->is_active : true) ? 'checked' : '' }}>
                                    فعال
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success">{{ $strategy ? 'ذخیره تغییرات' : 'ذخیره استراتژی' }}</button>
                    <a href="{{ route('strategy.index') }}" class="btn btn-default">انصراف</a>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
