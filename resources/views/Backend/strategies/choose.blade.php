@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-8 col-lg-offset-2">
        <div class="panel panel-default">
            <div class="panel-heading"><h3>انتخاب نوع استراتژی</h3></div>
            <div class="panel-body">
                <p>نوع استراتژی را انتخاب کنید.</p>
                <div class="row">
                    <div class="col-md-6">
                        <a href="{{ route('strategy.create', ['type' => 'generic']) }}" class="btn btn-primary btn-block" style="padding:25px 10px;">
                            <strong>استراتژی شرطی</strong><br>
                            <small>Rule Builder</small>
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="{{ route('strategy.create', ['type' => 'ma_trend']) }}" class="btn btn-success btn-block" style="padding:25px 10px;">
                            <strong>استراتژی روند با میانگین</strong><br>
                            <small>Moving Average Trend</small>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
