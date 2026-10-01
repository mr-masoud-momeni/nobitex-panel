@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="panel panel-default">
            <div class="panel-heading clearfix">
                <h3 style="display:inline-block;">معاملات</h3>
                <a href="{{ route('trade.create') }}" class="btn btn-success pull-left">+ ایجاد معامله</a>
            </div>

            <div class="panel-body">
                @if($trades->isEmpty())
                    <div class="alert alert-info">هنوز معامله‌ای ایجاد نشده است.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th>استراتژی</th>
                                <th>نوع</th>
                                <th>نماد</th>
                                <th>تایم‌فریم</th>
                                <th>سرمایه</th>
                                <th>وضعیت</th>
                                <th>نتیجه</th>
                                <th>عملیات</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($trades as $trade)
                                <tr>
                                    <td>{{ $trade->strategy->name }}</td>
                                    <td>
                                        @if($trade->type === 'backtest')
                                            <span class="label label-info">Backtest</span>
                                        @elseif($trade->type === 'paper')
                                            <span class="label label-warning">Paper</span>
                                        @else
                                            <span class="label label-danger">Live</span>
                                        @endif
                                    </td>
                                    <td>{{ $trade->symbol }}</td>
                                    <td>{{ $trade->timeframe }}</td>
                                    <td>{{ rtrim(rtrim(number_format($trade->initial_capital, 8, '.', ''), '0'), '.') }}</td>
                                    <td>
                                        @if($trade->status === 'draft')
                                            <span class="label label-default">آماده</span>
                                        @elseif($trade->status === 'running')
                                            <span class="label label-primary">در حال اجرا</span>
                                        @elseif($trade->status === 'stopped')
                                            <span class="label label-warning">متوقف شده</span>
                                        @elseif($trade->status === 'completed')
                                            <span class="label label-success">تکمیل شده</span>
                                        @else
                                            <span class="label label-danger">خطا</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($trade->result_percent !== null)
                                            {{ $trade->result_percent > 0 ? '+' : '' }}{{ $trade->result_percent }}%
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <a href="{{ route('trade.show', $trade) }}" class="btn btn-xs btn-default">مشاهده</a>

                                        @if($trade->status === 'draft')
                                            <form action="{{ route('trade.start', $trade) }}" method="post" style="display:inline;">
                                                @csrf
                                                <button type="submit" class="btn btn-xs btn-success">▶ اجرا</button>
                                            </form>
                                        @elseif($trade->status === 'running')
                                            <form action="{{ route('trade.stop', $trade) }}" method="post" style="display:inline;">
                                                @csrf
                                                <button type="submit" class="btn btn-xs btn-danger">■ توقف</button>
                                            </form>
                                        @elseif(in_array($trade->status, ['stopped', 'completed']))
                                            <span class="text-muted">اجرای مجدد: ایجاد معامله جدید</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
