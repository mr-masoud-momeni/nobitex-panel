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

                    <div class="form-group">
                        <label>نام استراتژی</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>توضیحات</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="panel panel-default strategy-rules-panel">
                        <div class="panel-heading clearfix">
                            <strong>شرایط ورود</strong>
                            <button type="button" class="btn btn-primary btn-xs pull-left" onclick="addRule('entry')">
                                + افزودن شرط
                            </button>
                        </div>
                        <div class="panel-body" id="entry-rules"></div>
                    </div>

                    <div class="panel panel-default strategy-rules-panel">
                        <div class="panel-heading clearfix">
                            <strong>شرایط خروج</strong>
                            <button type="button" class="btn btn-primary btn-xs pull-left" onclick="addRule('exit')">
                                + افزودن شرط
                            </button>
                        </div>
                        <div class="panel-body" id="exit-rules"></div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">
                            مدیریت معامله
                        </div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>ریسک هر معامله (%)</label>
                                    <input type="number" name="risk_percent" class="form-control" step="0.001" min="0" max="100">
                                </div>
                                <div class="col-md-4">
                                    <label>حد ضرر (%)</label>
                                    <input type="number" name="stop_loss" class="form-control" step="0.001" min="0" max="100">
                                </div>
                                <div class="col-md-4">
                                    <label>حد سود</label>
                                    <input type="number" name="take_profit" class="form-control" step="0.001" min="0">
                                </div>
                            </div>

                            <div style="margin-top: 15px;">
                                <label>
                                    <input type="checkbox" name="is_active" value="1" checked>
                                    فعال
                                </label>
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

<style>
    .strategy-rules-panel .panel-body {
        padding: 20px;
    }

    .strategy-rule-row {
        background: #f8f8f8;
        border: 1px solid #ddd;
        padding: 16px;
        margin-bottom: 10px;
        border-radius: 3px;
    }

    .strategy-rule-line {
        display: flex;
        align-items: flex-end;
        gap: 15px;
        margin-bottom: 14px;
    }

    .strategy-rule-line:last-child {
        margin-bottom: 0;
    }

    .strategy-rule-field {
        flex: 1;
        min-width: 0;
    }

    .strategy-rule-source {
        max-width: 360px;
    }

    .strategy-rule-source-parameters {
        flex: 2;
    }

    .strategy-rule-operator-line {
        max-width: 520px;
    }

    .strategy-rule-value-type {
        max-width: 220px;
    }

    .strategy-rule-value-field {
        flex: 2;
    }

    .strategy-rule-field label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
    }

    .rule-parameters,
    .strategy-comparison-indicator {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .strategy-parameter {
        min-width: 90px;
    }

    .strategy-parameter label {
        font-size: 11px;
        color: #777;
        margin-bottom: 3px;
        font-weight: normal;
    }

    .strategy-rule-remove {
        flex: 0 0 36px;
        width: 36px;
        height: 34px;
        margin-bottom: 0;
    }

    .rule-logical {
        margin: 8px 0;
        text-align: center;
    }

    .rule-logical select {
        width: 90px;
        display: inline-block;
        text-align: center;
        font-weight: 600;
    }

    .rule-value-parameters {
        flex: 1;
    }

    @media (max-width: 767px) {
        .strategy-rule-line {
            display: block;
        }

        .strategy-rule-field,
        .strategy-rule-source,
        .strategy-rule-value-type,
        .strategy-rule-value-field,
        .strategy-rule-operator-line {
            max-width: none;
            margin-bottom: 12px;
        }

        .strategy-rule-remove {
            margin-top: 5px;
        }
    }
</style>
@endsection

@section('scripts')
<script src="{{ asset('js/strategy-builder.js') }}"></script>
@endsection
