@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3>ایجاد استراتژی معاملاتی</h3>
            </div>

            <div class="panel-body">
                <form action="{{ route('strategy.store') }}" method="post">
                    @csrf

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>نام استراتژی</label>
                                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                            </div>

                            <div class="form-group">
                                <label>توضیحات</label>
                                <textarea name="description" class="form-control" rows="4">{{ old('description') }}</textarea>
                            </div>

                            <div class="form-group">
                                <label>شرایط ورود</label>
                                <textarea name="entry_conditions" class="form-control" rows="5" placeholder="مثلاً: EMA20 از EMA50 عبور کند و RSI14 کمتر از 70 باشد.">{{ old('entry_conditions') }}</textarea>
                                <p class="help-block">فعلاً به صورت توضیح متنی ذخیره می‌شود؛ Rule Builder را در مرحله بعد اضافه می‌کنیم.</p>
                            </div>

                            <div class="form-group">
                                <label>شرایط خروج</label>
                                <textarea name="exit_conditions" class="form-control" rows="5" placeholder="مثلاً: EMA20 زیر EMA50 برود.">{{ old('exit_conditions') }}</textarea>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="panel panel-default">
                                <div class="panel-heading">مدیریت معامله</div>
                                <div class="panel-body">
                                    <div class="form-group">
                                        <label>ریسک هر معامله (%)</label>
                                        <input type="number" name="risk_percent" class="form-control" step="0.001" min="0" max="100" value="{{ old('risk_percent') }}" placeholder="مثلاً 2">
                                    </div>

                                    <div class="form-group">
                                        <label>حد ضرر (%)</label>
                                        <input type="number" name="stop_loss" class="form-control" step="0.001" min="0" max="100" value="{{ old('stop_loss') }}" placeholder="مثلاً 2">
                                    </div>

                                    <div class="form-group">
                                        <label>حد سود (%)</label>
                                        <input type="number" name="take_profit" class="form-control" step="0.001" min="0" value="{{ old('take_profit') }}" placeholder="مثلاً 5">
                                    </div>

                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                                            استراتژی فعال باشد
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success">ذخیره استراتژی</button>
                    <a href="{{ route('strategy.index') }}" class="btn btn-default">انصراف</a>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
