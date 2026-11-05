@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(!$state || !$account)
            <div class="alert alert-warning">نماد BTCUSDT در منبع نوبیتکس پیدا نشد. ابتدا همگام‌سازی نمادها را اجرا کنید.</div>
        @else
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3>معاملات آزمایشی زنده</h3>
                    <p class="text-muted">BTCUSDT · 15m · EMA20 / EMA50 · فقط معاملات فرضی</p>
                </div>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-sm-3">
                            <h5>سرمایه اولیه</h5>
                            <h3>{{ number_format((float) $account->initial_capital, 2) }} USDT</h3>
                        </div>
                        <div class="col-sm-3">
                            <h5>موجودی نقدی</h5>
                            <h3>{{ number_format((float) $account->cash_balance, 4) }} USDT</h3>
                        </div>
                        <div class="col-sm-3">
                            <h5>سود/زیان تحقق‌یافته</h5>
                            <h3 class="{{ $account->realized_pnl >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $account->realized_pnl > 0 ? '+' : '' }}{{ number_format((float) $account->realized_pnl, 4) }} USDT
                            </h3>
                        </div>
                        <div class="col-sm-3">
                            <h5>ارزش تقریبی حساب</h5>
                            <h3>{{ number_format($estimatedEquity, 4) }} USDT</h3>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-3">
                            <strong>آخرین قیمت ثبت‌شده:</strong>
                            {{ $latestPrice ? number_format((float) $latestPrice, 2) : '—' }}
                        </div>
                        <div class="col-sm-3">
                            <strong>کارمزد هر سمت:</strong> {{ number_format((float) $account->fee_percent, 3) }}٪
                        </div>
                        <div class="col-sm-3">
                            <strong>وضعیت سیگنال:</strong> {{ $state->position_status === 'long' ? 'در موقعیت خرید' : 'بدون موقعیت' }}
                        </div>
                        <div class="col-sm-3">
                            <strong>سود/زیان باز:</strong>
                            @if($unrealizedPnl !== null)
                                <span class="{{ $unrealizedPnl >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $unrealizedPnl > 0 ? '+' : '' }}{{ number_format($unrealizedPnl, 4) }} USDT
                                </span>
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if(!$account->trades()->exists())
                <div class="panel panel-default">
                    <div class="panel-heading"><h4>تنظیمات حساب آزمایشی</h4></div>
                    <div class="panel-body">
                        <form method="post" action="{{ route('paper-trading.settings', $account) }}" class="form-inline">
                            @csrf
                            <div class="form-group" style="margin-left:15px;">
                                <label for="initial_capital">سرمایه اولیه (USDT)</label>
                                <input id="initial_capital" name="initial_capital" type="number" min="1" step="0.01" class="form-control" value="{{ old('initial_capital', $account->initial_capital) }}" required>
                            </div>
                            <div class="form-group" style="margin-left:15px;">
                                <label for="fee_percent">کارمزد هر سمت (درصد)</label>
                                <input id="fee_percent" name="fee_percent" type="number" min="0" max="5" step="0.001" class="form-control" value="{{ old('fee_percent', $account->fee_percent) }}" required>
                            </div>
                            <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
                        </form>
                        <p class="help-block" style="margin-top:10px;">مقادیر پیش‌فرض: ۱۰٬۰۰۰ تتر سرمایه و ۰٫۱٪ کارمزد برای هر سمت. تا پیش از اولین معامله قابل تغییر هستند.</p>
                    </div>
                </div>
            @endif

            <div class="panel panel-default">
                <div class="panel-heading"><h4>موقعیت باز</h4></div>
                <div class="panel-body">
                    @if($openTrade)
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead><tr><th>زمان ورود</th><th>قیمت ورود</th><th>مقدار BTC</th><th>ارزش خرید</th><th>کارمزد ورود</th><th>قیمت فعلی</th></tr></thead>
                                <tbody><tr>
                                    <td>{{ \Carbon\Carbon::createFromTimestamp($openTrade->entry_timestamp)->setTimezone('Asia/Tehran')->format('Y/m/d H:i') }}</td>
                                    <td>{{ number_format((float) $openTrade->entry_price, 2) }}</td>
                                    <td>{{ number_format((float) $openTrade->quantity, 8) }}</td>
                                    <td>{{ number_format((float) $openTrade->entry_notional, 4) }} USDT</td>
                                    <td>{{ number_format((float) $openTrade->entry_fee, 4) }} USDT</td>
                                    <td>{{ $latestPrice ? number_format((float) $latestPrice, 2) : '—' }}</td>
                                </tr></tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">در حال حاضر موقعیت بازی وجود ندارد.</p>
                    @endif
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><h4>تاریخچه معاملات (۵۰ مورد اخیر)</h4></div>
                <div class="panel-body">
                    @if($history->isEmpty())
                        <p class="text-muted">هنوز معامله‌ای بسته نشده است.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead><tr><th>ورود</th><th>خروج</th><th>قیمت خرید</th><th>قیمت فروش</th><th>مقدار</th><th>کارمزد کل</th><th>سود/زیان خالص</th><th>بازده</th></tr></thead>
                                <tbody>
                                @foreach($history as $trade)
                                    <tr>
                                        <td>{{ \Carbon\Carbon::createFromTimestamp($trade->entry_timestamp)->setTimezone('Asia/Tehran')->format('Y/m/d H:i') }}</td>
                                        <td>{{ $trade->exit_timestamp ? \Carbon\Carbon::createFromTimestamp($trade->exit_timestamp)->setTimezone('Asia/Tehran')->format('Y/m/d H:i') : '—' }}</td>
                                        <td>{{ number_format((float) $trade->entry_price, 2) }}</td>
                                        <td>{{ number_format((float) $trade->exit_price, 2) }}</td>
                                        <td>{{ number_format((float) $trade->quantity, 8) }}</td>
                                        <td>{{ number_format((float) $trade->entry_fee + (float) $trade->exit_fee, 4) }}</td>
                                        <td class="{{ $trade->net_pnl >= 0 ? 'text-success' : 'text-danger' }}">
                                            {{ $trade->net_pnl > 0 ? '+' : '' }}{{ number_format((float) $trade->net_pnl, 4) }} USDT
                                        </td>
                                        <td>{{ $trade->entry_notional > 0 ? number_format(((float) $trade->net_pnl / (float) $trade->entry_notional) * 100, 2) : '—' }}٪</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
