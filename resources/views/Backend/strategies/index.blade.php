@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="panel panel-default">
            <div class="panel-heading clearfix">
                <h3 style="display:inline-block;">استراتژی‌های معاملاتی</h3>
                <a href="{{ route('strategy.create') }}" class="btn btn-success pull-left">+ استراتژی جدید</a>
            </div>

            <div class="panel-body">
                @if($strategies->isEmpty())
                    <div class="alert alert-info">هنوز استراتژی‌ای ثبت نشده است.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th>نام</th>
                                <th>ریسک هر معامله</th>
                                <th>حد ضرر</th>
                                <th>حد سود</th>
                                <th>وضعیت</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($strategies as $strategy)
                                <tr>
                                    <td>{{ $strategy->name }}</td>
                                    <td>{{ $strategy->risk_percent !== null ? $strategy->risk_percent . '%' : '—' }}</td>
                                    <td>{{ $strategy->stop_loss !== null ? $strategy->stop_loss . '%' : '—' }}</td>
                                    <td>{{ $strategy->take_profit !== null ? $strategy->take_profit . '%' : '—' }}</td>
                                    <td>
                                        @if($strategy->is_active)
                                            <span class="label label-success">فعال</span>
                                        @else
                                            <span class="label label-default">غیرفعال</span>
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
