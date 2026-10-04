@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-10 col-lg-offset-1">
        @include('Backend.layouts.errors')
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3>{{ $strategy ? 'ویرایش استراتژی روند با میانگین' : 'ایجاد استراتژی روند با میانگین' }}</h3>
            </div>
            <div class="panel-body">
                <form action="{{ $strategy ? route('strategy.update', $strategy) : route('strategy.store') }}" method="post">
                    @csrf
                    <input type="hidden" name="strategy_type" value="ma_trend">

                    <div class="form-group">
                        <label>نام استراتژی</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $strategy->name ?? '') }}" required>
                    </div>

                    <div class="form-group">
                        <label>توضیحات</label>
                        <textarea name="description" class="form-control" rows="3">{{ old('description', $strategy->description ?? '') }}</textarea>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">میانگین متحرک</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label>نوع میانگین</label>
                                    <select name="config[ma_type]" class="form-control">
                                        <option value="ema" selected>EMA</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label>دوره</label>
                                    <input type="number" name="config[ma_period]" class="form-control" min="1" max="1000" step="1"
                                           value="{{ old('config.ma_period', $strategy->config['ma_period'] ?? 20) }}" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">جهت معامله</div>
                        <div class="panel-body">
                            <select name="direction" class="form-control" required>
                                <option value="long" {{ old('direction', $strategy->direction ?? 'long') === 'long' ? 'selected' : '' }}>Long (خرید)</option>
                                <option value="short" {{ old('direction', $strategy->direction ?? 'long') === 'short' ? 'selected' : '' }}>Short (فروش)</option>
                                <option value="both" {{ old('direction', $strategy->direction ?? 'long') === 'both' ? 'selected' : '' }}>Both (هر دو)</option>
                            </select>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">ورود</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>محدوده Pullback (%)</label>
                                    <input type="number" name="config[pullback_zone_percent]" class="form-control" min="0" max="100" step="0.001"
                                           value="{{ old('config.pullback_zone_percent', $strategy->config['pullback_zone_percent'] ?? 0.2) }}" required>
                                    <small class="text-muted">محدوده به‌صورت متقارن در دو طرف EMA در نظر گرفته می‌شود.</small>
                                </div>
                                <div class="col-md-4">
                                    <label>حداقل ارتفاع کندل تأیید (%)</label>
                                    <input type="number" name="config[min_confirmation_candle_percent]" class="form-control" min="0" max="100" step="0.001"
                                           value="{{ old('config.min_confirmation_candle_percent', $strategy->config['min_confirmation_candle_percent'] ?? 0.3) }}" required>
                                    <small class="text-muted">ارتفاع = (High - Low) / Low × 100</small>
                                </div>
                                <div class="col-md-4">
                                    <label>تعداد کندل خروج</label>
                                    <input type="number" name="config[exit_sequence_count]" class="form-control" min="2" max="20" step="1"
                                           value="{{ old('config.exit_sequence_count', $strategy->config['exit_sequence_count'] ?? 3) }}" required>
                                    <small class="text-muted">پیش‌فرض ۳؛ پس از هر تکمیل، شمارش از کندل سوم ادامه پیدا می‌کند.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">مدیریت معامله</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>ریسک هر معامله (%)</label>
                                    <input type="number" name="risk_percent" class="form-control" step="0.001" min="0" max="100"
                                           value="{{ old('risk_percent', $strategy->risk_percent ?? '') }}">
                                </div>
                                <div class="col-md-4">
                                    <label>حد ضرر (%)</label>
                                    <input type="number" name="stop_loss" class="form-control" step="0.001" min="0" max="100"
                                           value="{{ old('stop_loss', $strategy->stop_loss ?? '') }}">
                                </div>
                                <div class="col-md-4">
                                    <label>حد سود (%)</label>
                                    <input type="number" name="take_profit" class="form-control" step="0.001" min="0"
                                           value="{{ old('take_profit', $strategy->take_profit ?? '') }}">
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
