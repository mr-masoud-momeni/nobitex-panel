@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        <div class="panel panel-default">
            <div class="panel-heading clearfix">
                <h3 style="display:inline-block;">جزئیات معامله #{{ $trade->id }}</h3>
                <a href="{{ route('trade.index') }}" class="btn btn-default pull-left">بازگشت</a>
            </div>

            <div class="panel-body">
                <div class="row">
                    <div class="col-md-4"><strong>استراتژی:</strong> {{ $trade->strategy->name }}</div>
                    <div class="col-md-4"><strong>نوع:</strong> {{ ucfirst($trade->type) }}</div>
                    <div class="col-md-4"><strong>وضعیت:</strong> {{ $trade->status }}</div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-4"><strong>منبع:</strong> {{ $trade->market->name ?? "—" }}</div>
                    <div class="col-md-4"><strong>نماد:</strong> {{ $trade->marketSymbol->display_name ?? $trade->symbol }}</div>
                    <div class="col-md-4"><strong>تایم‌فریم:</strong> {{ $trade->timeframe }}</div>
                    <div class="col-md-4"><strong>سرمایه اولیه:</strong> {{ $trade->initial_capital }}</div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-3"><strong>سود/زیان:</strong>
                        @if($trade->result_amount !== null)
                            {{ number_format($trade->result_amount, 2) }}
                        @else
                            —
                        @endif
                    </div>
                    <div class="col-md-3"><strong>درصد نتیجه:</strong>
                        @if($trade->result_percent !== null)
                            {{ number_format($trade->result_percent, 2) }}%
                        @else
                            —
                        @endif
                    </div>
                    <div class="col-md-2"><strong>معاملات:</strong> {{ $trade->total_trades ?? '—' }}</div>
                    <div class="col-md-2"><strong>سودده:</strong> {{ $trade->winning_trades ?? '—' }}</div>
                    <div class="col-md-2"><strong>زیان‌ده:</strong> {{ $trade->losing_trades ?? '—' }}</div>
                </div>

                <hr>

                <hr>

                <h4>گزارش ورود و خروج بک‌تست</h4>
                @php($backtestLog = is_array($trade->backtest_log) ? $trade->backtest_log : (json_decode($trade->backtest_log ?? '[]', true) ?: []))
                @if(count($backtestLog))
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>ورود</th>
                                    <th>قیمت ورود</th>
                                    <th>خروج</th>
                                    <th>قیمت خروج</th>
                                    <th>سود/زیان</th>
                                    <th>درصد</th>
                                    <th>علت خروج</th>
                                    <th>سرمایه پس از معامله</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($backtestLog as $index => $execution)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $execution['entry_time'] ?? '—' }}</td>
                                        <td>{{ isset($execution['entry_price']) ? number_format($execution['entry_price'], 2) : '—' }}</td>
                                        <td>{{ $execution['exit_time'] ?? '—' }}</td>
                                        <td>{{ isset($execution['exit_price']) ? number_format($execution['exit_price'], 2) : '—' }}</td>
                                        <td>{{ isset($execution['profit']) ? number_format($execution['profit'], 2) : '—' }}</td>
                                        <td>{{ isset($execution['profit_percent']) ? number_format($execution['profit_percent'], 2) : '—' }}%</td>
                                        <td>
                                            @switch($execution['exit_reason'] ?? null)
                                                @case('exit_rule') شرط خروج @break
                                                @case('stop_loss_or_take_profit') حد ضرر/حد سود @break
                                                @case('end_of_test') پایان بک‌تست @break
                                                @default —
                                            @endswitch
                                        </td>
                                        <td>{{ isset($execution['cash_after']) ? number_format($execution['cash_after'], 2) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($trade->status === 'completed')
                    <div class="alert alert-info">برای این بک‌تست معامله‌ای ثبت نشده است.</div>
                @else
                    <div class="alert alert-info">پس از اجرای بک‌تست، جزئیات ورود و خروج اینجا نمایش داده می‌شود.</div>
                @endif

                <h4>استراتژی مورد استفاده</h4>
                <p>{{ $trade->strategy->description ?: 'بدون توضیحات' }}</p>
                <p>
                    ریسک: {{ $trade->strategy->risk_percent ?? '—' }}٪ |
                    حد ضرر: {{ $trade->strategy->stop_loss ?? '—' }}٪ |
                    حد سود: {{ $trade->strategy->take_profit ?? '—' }}٪
                </p>

                <div style="margin-top:20px;">
                    @if($trade->status === 'draft')
                        <a href="{{ route('trade.edit', $trade) }}" class="btn btn-warning">ویرایش</a>
                    @endif

                    <form action="{{ route('trade.duplicate', $trade) }}" method="post" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-info">داپلیکیت</button>
                    </form>

                    @if($trade->status !== 'running')
                        <form action="{{ route('trade.destroy', $trade) }}" method="post" style="display:inline;" onsubmit="return confirm('آیا از حذف این معامله مطمئن هستید؟');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">حذف</button>
                        </form>
                    @endif

                    @if($trade->status === 'draft')
                        <form action="{{ route('trade.start', $trade) }}" method="post" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-success">▶ اجرا</button>
                        </form>
                    @elseif($trade->status === 'running')
                        <form action="{{ route('trade.stop', $trade) }}" method="post" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-danger">■ توقف</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
