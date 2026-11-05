@extends('Backend.layouts.Master')

@section('HeaderLinks')
<style>
    .paper-dashboard { direction: rtl; color: #263238; }
    .paper-dashboard .page-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:15px; margin: 4px 0 22px; }
    .paper-dashboard .page-heading h2 { margin:0 0 7px; font-size:24px; font-weight:700; }
    .paper-dashboard .page-heading p { margin:0; color:#7b8790; }
    .paper-dashboard .heading-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .paper-dashboard .live-indicator { display:inline-flex; align-items:center; gap:7px; color:#26845d; font-size:12px; font-weight:600; }
    .paper-dashboard .live-dot { width:8px; height:8px; display:inline-block; border-radius:50%; background:#2eaa75; box-shadow:0 0 0 4px rgba(46,170,117,.12); }
    .paper-dashboard .refresh-note { color:#8a949b; font-size:11px; }
    .paper-dashboard .btn-refresh { border:1px solid #dce2e5; background:#fff; color:#45545c; border-radius:6px; padding:7px 12px; }
    .paper-dashboard .btn-refresh:hover { background:#f7f9fa; }
    .paper-dashboard .metric-card { min-height:124px; padding:18px; margin-bottom:18px; background:#fff; border:1px solid #e8edef; border-radius:9px; box-shadow:0 2px 8px rgba(24,39,48,.035); }
    .paper-dashboard .metric-label { color:#78858d; font-size:12px; margin-bottom:13px; }
    .paper-dashboard .metric-value { color:#25343b; font-size:23px; font-weight:700; line-height:1.35; direction:ltr; text-align:right; white-space:nowrap; }
    .paper-dashboard .metric-unit { color:#849098; font-size:12px; font-weight:500; margin-right:4px; }
    .paper-dashboard .metric-foot { color:#89949a; font-size:11px; margin-top:8px; }
    .paper-dashboard .positive { color:#21966a !important; }
    .paper-dashboard .negative { color:#d65c5c !important; }
    .paper-dashboard .section-panel { margin-bottom:20px; background:#fff; border:1px solid #e7ecee; border-radius:9px; box-shadow:0 2px 8px rgba(24,39,48,.03); overflow:hidden; }
    .paper-dashboard .section-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 18px; border-bottom:1px solid #edf0f1; }
    .paper-dashboard .section-heading h3 { margin:0; font-size:15px; font-weight:700; }
    .paper-dashboard .section-heading small { color:#8b969c; font-size:11px; }
    .paper-dashboard .section-body { padding:18px; }
    .paper-dashboard .status-pill { display:inline-block; padding:5px 10px; border-radius:20px; font-size:11px; background:#edf8f2; color:#27865e; }
    .paper-dashboard .status-pill.neutral { background:#f1f3f4; color:#77838a; }
    .paper-dashboard .table { margin:0; font-size:12px; }
    .paper-dashboard .table>thead>tr>th { color:#7d8990; background:#fafbfb; border-bottom:1px solid #e8edef; font-weight:600; white-space:nowrap; }
    .paper-dashboard .table>tbody>tr>td { vertical-align:middle; border-top:1px solid #f0f2f3; white-space:nowrap; }
    .paper-dashboard .table-responsive { border:0; margin:0; }
    .paper-dashboard .empty-state { padding:28px 12px; text-align:center; color:#879299; }
    .paper-dashboard .empty-state strong { display:block; color:#536168; margin-bottom:6px; font-size:13px; }
    .paper-dashboard .settings-grid { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; }
    .paper-dashboard .settings-grid .form-group { min-width:190px; margin:0; }
    .paper-dashboard .settings-grid label { display:block; font-size:12px; margin-bottom:7px; color:#65727a; }
    .paper-dashboard .settings-grid .form-control { height:38px; border-color:#dfe5e7; border-radius:6px; box-shadow:none; }
    .paper-dashboard .btn-save { height:38px; padding:0 18px; border:0; border-radius:6px; background:#344c57; color:#fff; }
    .paper-dashboard .btn-save:hover { background:#263c46; color:#fff; }
    .paper-dashboard .helper-text { margin:12px 0 0; color:#89949a; font-size:11px; }
    .paper-dashboard .market-meta { display:flex; gap:22px; flex-wrap:wrap; color:#77848b; font-size:12px; }
    .paper-dashboard .market-meta strong { color:#3c4b52; font-weight:600; }
    @media(max-width:767px) {
        .paper-dashboard .page-heading { display:block; }
        .paper-dashboard .heading-actions { margin-top:14px; }
        .paper-dashboard .metric-value { font-size:20px; }
        .paper-dashboard .section-body { padding:12px; }
    }
</style>
@endsection

@section('content')
<div class="paper-dashboard">
    @include('Backend.layouts.errors')

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(!$state || !$account)
        <div class="alert alert-warning">
            نماد BTCUSDT در منبع نوبیتکس پیدا نشد. ابتدا همگام‌سازی نمادها را اجرا کنید.
        </div>
    @else
        <div class="page-heading">
            <div>
                <h2>معاملات آزمایشی زنده</h2>
                <p>پایش اجرای استراتژی EMA20 / EMA50 روی BTCUSDT در تایم‌فریم ۱۵ دقیقه</p>
            </div>
            <div class="heading-actions">
                <span class="live-indicator"><span class="live-dot"></span> حساب آزمایشی فعال</span>
                <span class="refresh-note">به‌روزرسانی خودکار هر ۶۰ ثانیه</span>
                <button type="button" class="btn-refresh" onclick="window.location.reload()">↻ تازه‌سازی</button>
            </div>
        </div>

        <div class="row">
            <div class="col-md-3 col-sm-6">
                <div class="metric-card">
                    <div class="metric-label">ارزش تقریبی حساب</div>
                    <div class="metric-value">{{ number_format((float) $estimatedEquity, 2) }} <span class="metric-unit">USDT</span></div>
                    <div class="metric-foot">موجودی نقدی + ارزش موقعیت باز پس از کارمزد خروج</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="metric-card">
                    <div class="metric-label">سود / زیان کل</div>
                    <div class="metric-value {{ $totalPnl >= 0 ? 'positive' : 'negative' }}">
                        {{ $totalPnl > 0 ? '+' : '' }}{{ number_format($totalPnl, 4) }} <span class="metric-unit">USDT</span>
                    </div>
                    <div class="metric-foot {{ $totalPnlPercent >= 0 ? 'positive' : 'negative' }}">
                        {{ $totalPnlPercent > 0 ? '+' : '' }}{{ number_format($totalPnlPercent, 2) }}٪ بازده نسبت به سرمایه اولیه
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="metric-card">
                    <div class="metric-label">موجودی نقدی آزاد</div>
                    <div class="metric-value">{{ number_format((float) $account->cash_balance, 4) }} <span class="metric-unit">USDT</span></div>
                    <div class="metric-foot">سرمایه قابل استفاده برای ورود بعدی</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="metric-card">
                    <div class="metric-label">سود / زیان تحقق‌یافته</div>
                    <div class="metric-value {{ $account->realized_pnl >= 0 ? 'positive' : 'negative' }}">
                        {{ $account->realized_pnl > 0 ? '+' : '' }}{{ number_format((float) $account->realized_pnl, 4) }} <span class="metric-unit">USDT</span>
                    </div>
                    <div class="metric-foot">سرمایه اولیه: {{ number_format((float) $account->initial_capital, 2) }} USDT</div>
                </div>
            </div>
        </div>

        <div class="section-panel">
            <div class="section-heading">
                <h3>وضعیت بازار و استراتژی</h3>
                <span class="status-pill {{ $state->position_status === 'long' ? '' : 'neutral' }}">
                    {{ $state->position_status === 'long' ? 'موقعیت خرید فعال' : 'بدون موقعیت' }}
                </span>
            </div>
            <div class="section-body">
                <div class="market-meta">
                    <span>نماد: <strong>BTCUSDT</strong></span>
                    <span>تایم‌فریم: <strong>۱۵ دقیقه</strong></span>
                    <span>آخرین قیمت ثبت‌شده: <strong>{{ $latestPrice !== null ? number_format($latestPrice, 2).' USDT' : '—' }}</strong></span>
                    <span>کارمزد هر سمت: <strong>{{ number_format((float) $account->fee_percent, 3) }}٪</strong></span>
                    <span>زمان آخرین کندل: <strong>{{ $lastCandleTimestamp ? CarbonCarbon::createFromTimestamp($lastCandleTimestamp)->setTimezone('Asia/Tehran')->format('Y/m/d H:i') : '—' }}</strong></span>
                </div>
            </div>
        </div>

        @if(!$account->trades()->exists())
            <div class="section-panel">
                <div class="section-heading">
                    <h3>تنظیمات حساب آزمایشی</h3>
                    <small>تا پیش از اولین معامله قابل تغییر است</small>
                </div>
                <div class="section-body">
                    <form method="post" action="{{ route('paper-trading.settings', $account) }}">
                        @csrf
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="initial_capital">سرمایه اولیه (USDT)</label>
                                <input id="initial_capital" name="initial_capital" type="number" min="1" step="0.01" class="form-control" value="{{ old('initial_capital', $account->initial_capital) }}" required>
                            </div>
                            <div class="form-group">
                                <label for="fee_percent">کارمزد هر سمت (درصد)</label>
                                <input id="fee_percent" name="fee_percent" type="number" min="0" max="5" step="0.001" class="form-control" value="{{ old('fee_percent', $account->fee_percent) }}" required>
                            </div>
                            <button type="submit" class="btn-save">ذخیره تنظیمات</button>
                        </div>
                        <p class="helper-text">مقادیر پیش‌فرض: ۱۰٬۰۰۰ تتر سرمایه و ۰٫۱٪ کارمزد برای هر سمت معامله.</p>
                    </form>
                </div>
            </div>
        @endif

        <div class="section-panel">
            <div class="section-heading">
                <h3>موقعیت باز</h3>
                @if($openTrade)
                    <span class="status-pill">در حال معامله</span>
                @else
                    <span class="status-pill neutral">موقعیت بازی وجود ندارد</span>
                @endif
            </div>
            @if($openTrade)
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>زمان ورود</th>
                                <th>قیمت ورود</th>
                                <th>قیمت مبنا</th>
                                <th>مقدار BTC</th>
                                <th>ارزش ورود</th>
                                <th>کارمزد ورود</th>
                                <th>سود / زیان شناور</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>{{ CarbonCarbon::createFromTimestamp($openTrade->entry_timestamp)->setTimezone('Asia/Tehran')->format('Y/m/d H:i') }}</td>
                                <td>{{ number_format((float) $openTrade->entry_price, 2) }}</td>
                                <td>{{ $latestPrice !== null ? number_format($latestPrice, 2) : '—' }}</td>
                                <td>{{ number_format((float) $openTrade->quantity, 8) }}</td>
                                <td>{{ number_format((float) $openTrade->entry_notional, 4) }} USDT</td>
                                <td>{{ number_format((float) $openTrade->entry_fee, 4) }} USDT</td>
                                <td class="{{ $unrealizedPnl !== null && $unrealizedPnl >= 0 ? 'positive' : 'negative' }}">
                                    @if($unrealizedPnl !== null)
                                        {{ $unrealizedPnl > 0 ? '+' : '' }}{{ number_format($unrealizedPnl, 4) }} USDT
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty-state">
                    <strong>در حال حاضر معامله بازی نداریم</strong>
                    با ثبت سیگنال ورود معتبر توسط استراتژی، موقعیت در این بخش نمایش داده می‌شود.
                </div>
            @endif
        </div>

        <div class="section-panel">
            <div class="section-heading">
                <h3>تاریخچه معاملات بسته‌شده</h3>
                <small>۵۰ معامله اخیر</small>
            </div>
            @if($history->isEmpty())
                <div class="empty-state">
                    <strong>هنوز معامله‌ای بسته نشده است</strong>
                    پس از اولین چرخه ورود و خروج، گزارش معاملات در این بخش قرار می‌گیرد.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>زمان ورود</th>
                                <th>زمان خروج</th>
                                <th>قیمت خرید</th>
                                <th>قیمت فروش</th>
                                <th>مقدار BTC</th>
                                <th>کارمزد کل</th>
                                <th>سود / زیان خالص</th>
                                <th>بازده</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($history as $trade)
                                <tr>
                                    <td>{{ CarbonCarbon::createFromTimestamp($trade->entry_timestamp)->setTimezone('Asia/Tehran')->format('Y/m/d H:i') }}</td>
                                    <td>{{ $trade->exit_timestamp ? CarbonCarbon::createFromTimestamp($trade->exit_timestamp)->setTimezone('Asia/Tehran')->format('Y/m/d H:i') : '—' }}</td>
                                    <td>{{ number_format((float) $trade->entry_price, 2) }}</td>
                                    <td>{{ number_format((float) $trade->exit_price, 2) }}</td>
                                    <td>{{ number_format((float) $trade->quantity, 8) }}</td>
                                    <td>{{ number_format((float) $trade->entry_fee + (float) $trade->exit_fee, 4) }} USDT</td>
                                    <td class="{{ $trade->net_pnl >= 0 ? 'positive' : 'negative' }}">
                                        {{ $trade->net_pnl > 0 ? '+' : '' }}{{ number_format((float) $trade->net_pnl, 4) }} USDT
                                    </td>
                                    <td class="{{ $trade->net_pnl >= 0 ? 'positive' : 'negative' }}">
                                        {{ $trade->entry_notional > 0 ? number_format(((float) $trade->net_pnl / (float) $trade->entry_notional) * 100, 2) : '—' }}٪
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
    (function () {
        window.setTimeout(function () {
            window.location.reload();
        }, 60000);
    })();
</script>
@endsection
