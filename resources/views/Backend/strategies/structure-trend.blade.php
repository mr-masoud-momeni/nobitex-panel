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
                        <div class="panel-heading">ساختار بازار</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>قدرت Swing</label>
                                    <input type="number" name="config[structure_swing_strength]" class="form-control" min="2" max="10" step="1"
                                           value="{{ old('config.structure_swing_strength', $strategy->config['structure_swing_strength'] ?? 3) }}" required>
                                    <small class="text-muted">تعداد کندل‌های دو طرف برای تأیید سقف/کف.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>حداقل تغییر Swing (%)</label>
                                    <input type="number" name="config[structure_min_swing_percent]" class="form-control" min="0" max="100" step="0.01"
                                           value="{{ old('config.structure_min_swing_percent', $strategy->config['structure_min_swing_percent'] ?? 0.4) }}" required>
                                    <small class="text-muted">نوسان‌های خیلی کوچک را از ساختار حذف می‌کند.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>بازه تشخیص Range (کندل)</label>
                                    <input type="number" name="config[range_lookback_candles]" class="form-control" min="10" max="500" step="1"
                                           value="{{ old('config.range_lookback_candles', $strategy->config['range_lookback_candles'] ?? 30) }}" required>
                                </div>
                            </div>
                            <div class="row" style="margin-top:15px;">
                                <div class="col-md-4">
                                    <label>حداکثر عرض Range (%)</label>
                                    <input type="number" name="config[range_max_width_percent]" class="form-control" min="0.1" max="100" step="0.1"
                                           value="{{ old('config.range_max_width_percent', $strategy->config['range_max_width_percent'] ?? 3) }}" required>
                                    <small class="text-muted">اگر High تا Low این بازه کمتر از این مقدار باشد، بازار Range در نظر گرفته می‌شود.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>بافر شکست (%)</label>
                                    <input type="number" name="config[breakout_buffer_percent]" class="form-control" min="0" max="20" step="0.01"
                                           value="{{ old('config.breakout_buffer_percent', $strategy->config['breakout_buffer_percent'] ?? 0.1) }}" required>
                                    <small class="text-muted">برای جلوگیری از شکست‌های بسیار جزئی.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>حداکثر کندل تا پولبک</label>
                                    <input type="number" name="config[pullback_max_bars]" class="form-control" min="1" max="50" step="1"
                                           value="{{ old('config.pullback_max_bars', $strategy->config['pullback_max_bars'] ?? 8) }}" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">میانگین متحرک به‌عنوان فیلتر</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label>نوع میانگین</label>
                                    <select name="config[ma_type]" class="form-control">
                                        <option value="ema" selected>EMA</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label>دوره EMA</label>
                                    <input type="number" name="config[ma_period]" class="form-control" min="1" max="1000" step="1"
                                           value="{{ old('config.ma_period', $strategy->config['ma_period'] ?? 20) }}" required>
                                    <small class="text-muted">EMA روند را تشخیص نمی‌دهد؛ فقط جهت شکست و پولبک را تأیید می‌کند.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">پولبک و ورود</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label>محدوده Pullback (%)</label>
                                    <input type="number" name="config[pullback_zone_percent]" class="form-control" min="0" max="100" step="0.01"
                                           value="{{ old('config.pullback_zone_percent', $strategy->config['pullback_zone_percent'] ?? 0.5) }}" required>
                                    <small class="text-muted">پولبک به EMA یا ناحیه شکست را پوشش می‌دهد.</small>
                                </div>
                                <div class="col-md-6">
                                    <label>حداقل ارتفاع کندل تأیید (%)</label>
                                    <input type="number" name="config[min_confirmation_candle_percent]" class="form-control" min="0" max="100" step="0.01"
                                           value="{{ old('config.min_confirmation_candle_percent', $strategy->config['min_confirmation_candle_percent'] ?? 0.3) }}" required>
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
