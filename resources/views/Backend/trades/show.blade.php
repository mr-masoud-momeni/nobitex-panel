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
                    <div class="col-md-4"><strong>نتیجه:</strong>
                        @if($trade->result_percent !== null)
                            {{ $trade->result_percent }}%
                        @else
                            هنوز نتیجه‌ای ثبت نشده است.
                        @endif
                    </div>
                    <div class="col-md-4"><strong>تعداد معاملات:</strong> {{ $trade->total_trades ?? '—' }}</div>
                    <div class="col-md-4"><strong>معاملات سودده:</strong> {{ $trade->winning_trades ?? '—' }}</div>
                </div>

                <hr>

                <h4>استراتژی مورد استفاده</h4>
                <p>{{ $trade->strategy->description ?: 'بدون توضیحات' }}</p>
                <p>
                    ریسک: {{ $trade->strategy->risk_percent ?? '—' }}٪ |
                    حد ضرر: {{ $trade->strategy->stop_loss ?? '—' }}٪ |
                    حد سود: {{ $trade->strategy->take_profit ?? '—' }}٪
                </p>

                <div style="margin-top:20px;">
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
