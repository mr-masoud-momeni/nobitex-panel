@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3>ایجاد معامله</h3>
            </div>

            <div class="panel-body">
                <form action="{{ route('trade.store') }}" method="post">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>استراتژی</label>
                            <select name="strategy_id" class="form-control" required>
                                <option value="">انتخاب استراتژی</option>
                                @foreach($strategies as $strategy)
                                    <option value="{{ $strategy->id }}">{{ $strategy->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>نوع معامله</label>
                            <select name="type" id="trade-type" class="form-control" required>
                                <option value="backtest">Backtest</option>
                                <option value="paper">Paper</option>
                                <option value="live">Live</option>
                            </select>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>نماد</label>
                            <input type="text" name="symbol" class="form-control" placeholder="BTC/USDT" required>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>تایم‌فریم</label>
                            <select name="timeframe" class="form-control" required>
                                <option value="1m">1m</option>
                                <option value="5m">5m</option>
                                <option value="15m" selected>15m</option>
                                <option value="30m">30m</option>
                                <option value="1h">1h</option>
                                <option value="4h">4h</option>
                                <option value="1d">1d</option>
                            </select>
                        </div>

                        <div class="col-md-4 form-group">
                            <label>سرمایه اولیه</label>
                            <input type="number" name="initial_capital" class="form-control" step="0.00000001" min="0.00000001" required>
                        </div>

                        <div class="col-md-4 form-group">
                            <label>کارمزد (%)</label>
                            <input type="number" name="fee_percent" class="form-control" step="0.0001" min="0" value="0.1">
                        </div>
                    </div>

                    <div id="backtest-fields" class="panel panel-default" style="margin-top: 15px;">
                        <div class="panel-heading">بازه بک‌تست</div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label>از تاریخ</label>
                                    <input type="datetime-local" name="start_date" class="form-control">
                                </div>
                                <div class="col-md-6 form-group">
                                    <label>تا تاریخ</label>
                                    <input type="datetime-local" name="end_date" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success">ایجاد معامله</button>
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

    function toggleBacktestFields() {
        fields.style.display = type.value === 'backtest' ? '' : 'none';
    }

    type.addEventListener('change', toggleBacktestFields);
    toggleBacktestFields();
})();
</script>
@endsection
