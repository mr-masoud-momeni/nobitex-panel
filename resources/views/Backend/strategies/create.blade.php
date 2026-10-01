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

                    <div class="panel panel-default">
                        <div class="panel-heading clearfix">
                            <strong>شرایط ورود</strong>
                            <button type="button" class="btn btn-primary btn-xs pull-left" onclick="addRule('entry')">
                                + افزودن شرط
                            </button>
                        </div>
                        <div class="panel-body" id="entry-rules"></div>
                    </div>

                    <div class="panel panel-default">
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
    .strategy-rule-row {
        background: #f8f8f8;
        border: 1px solid #ddd;
        padding: 15px;
        margin-bottom: 10px;
    }

    .strategy-rule-row .form-group {
        margin-bottom: 10px;
    }

    .rule-logical {
        margin: 0 0 10px;
        text-align: center;
    }

    .rule-logical select {
        width: auto;
        display: inline-block;
    }

    .strategy-rule-remove {
        margin-top: 25px;
    }

    .strategy-rule-parameters {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .strategy-rule-parameters .form-control {
        flex: 1 1 100px;
        min-width: 80px;
    }
</style>

<script>
(function () {
    var ruleIndex = 0;

    var indicators = {
        price: {
            label: 'قیمت',
            parameters: []
        },
        ema: {
            label: 'EMA',
            parameters: ['period']
        },
        sma: {
            label: 'SMA',
            parameters: ['period']
        },
        rsi: {
            label: 'RSI',
            parameters: ['period']
        },
        macd: {
            label: 'MACD',
            parameters: ['fast', 'slow', 'signal']
        },
        volume: {
            label: 'حجم',
            parameters: []
        }
    };

    var operators = [
        ['>', 'بزرگ‌تر از'],
        ['<', 'کوچک‌تر از'],
        ['>=', 'بزرگ‌تر یا مساوی'],
        ['<=', 'کوچک‌تر یا مساوی'],
        ['=', 'مساوی'],
        ['crosses_above', 'عبور رو به بالا از'],
        ['crosses_below', 'عبور رو به پایین از']
    ];

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function indicatorOptions() {
        return Object.keys(indicators).map(function (key) {
            return '<option value="' + escapeHtml(key) + '">' +
                escapeHtml(indicators[key].label) +
                '</option>';
        }).join('');
    }

    function operatorOptions() {
        return operators.map(function (operator) {
            return '<option value="' + escapeHtml(operator[0]) + '">' +
                escapeHtml(operator[1]) +
                '</option>';
        }).join('');
    }

    function parameterInputs(index, fieldName, indicatorKey) {
        var parameters = indicators[indicatorKey].parameters;

        if (!parameters.length) {
            return '<span class="text-muted">بدون پارامتر</span>';
        }

        return '<div class="strategy-rule-parameters">' +
            parameters.map(function (parameter) {
                var defaultValue = parameter === 'period' ? 14 :
                    parameter === 'fast' ? 12 :
                    parameter === 'slow' ? 26 : 9;

                return '<input type="number" min="1" step="1" ' +
                    'class="form-control input-sm" ' +
                    'name="rules[' + index + '][' + fieldName + '][' + parameter + ']" ' +
                    'placeholder="' + escapeHtml(parameter) + '" ' +
                    'value="' + defaultValue + '">';
            }).join('') +
            '</div>';
    }

    function valueIndicatorParameters(index, indicatorKey) {
        return parameterInputs(index, 'value][parameters', indicatorKey);
    }

    function buildValue(index, row) {
        var valueType = row.querySelector('.rule-value-type').value;
        var valueContainer = row.querySelector('.rule-value');

        if (valueType === 'number') {
            valueContainer.innerHTML =
                '<input type="number" step="any" name="rules[' + index + '][value]" ' +
                'class="form-control" placeholder="مثلاً 70">';
            return;
        }

        valueContainer.innerHTML =
            '<select name="rules[' + index + '][value][indicator]" ' +
            'class="form-control rule-value-indicator">' +
            indicatorOptions() +
            '</select>' +
            '<div class="rule-value-parameters" style="margin-top: 8px;"></div>';

        refreshValueIndicator(row);
    }

    function refreshValueIndicator(row) {
        var index = row.getAttribute('data-index');
        var indicatorKey = row.querySelector('.rule-value-indicator').value;
        var container = row.querySelector('.rule-value-parameters');

        container.innerHTML = valueIndicatorParameters(index, indicatorKey);
    }

    function refreshIndicator(row) {
        var index = row.getAttribute('data-index');
        var indicatorKey = row.querySelector('.rule-indicator').value;
        var container = row.querySelector('.rule-parameters');

        container.innerHTML = parameterInputs(index, 'parameters', indicatorKey);
    }

    function createRuleRow(index, type) {
        return '' +
            '<div class="strategy-rule-row" data-index="' + index + '">' +
                '<input type="hidden" name="rules[' + index + '][type]" value="' + escapeHtml(type) + '">' +
                '<input type="hidden" name="rules[' + index + '][sort_order]" value="' + index + '">' +

                '<div class="row">' +
                    '<div class="col-md-3 form-group">' +
                        '<label>اندیکاتور</label>' +
                        '<select name="rules[' + index + '][indicator]" class="form-control rule-indicator">' +
                            indicatorOptions() +
                        '</select>' +
                    '</div>' +

                    '<div class="col-md-3 form-group">' +
                        '<label>پارامتر</label>' +
                        '<div class="rule-parameters"></div>' +
                    '</div>' +

                    '<div class="col-md-3 form-group">' +
                        '<label>عملگر</label>' +
                        '<select name="rules[' + index + '][operator]" class="form-control">' +
                            operatorOptions() +
                        '</select>' +
                    '</div>' +

                    '<div class="col-md-3 form-group">' +
                        '<label>نوع مقدار</label>' +
                        '<select name="rules[' + index + '][value_type]" class="form-control rule-value-type">' +
                            '<option value="number">عدد</option>' +
                            '<option value="indicator">اندیکاتور</option>' +
                        '</select>' +
                    '</div>' +

                    '<div class="col-md-11 form-group">' +
                        '<label>مقدار / اندیکاتور دوم</label>' +
                        '<div class="rule-value"></div>' +
                    '</div>' +

                    '<div class="col-md-1 form-group text-center">' +
                        '<label>&nbsp;</label>' +
                        '<button type="button" class="btn btn-danger strategy-rule-remove" title="حذف شرط">×</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
    }

    window.addRule = function (type) {
        var container = document.getElementById(type + '-rules');
        var index = ruleIndex++;

        if (container.querySelector('.strategy-rule-row')) {
            container.insertAdjacentHTML(
                'beforeend',
                '<div class="rule-logical">' +
                    '<select name="rules[' + index + '][logical_operator]" class="form-control input-sm">' +
                        '<option value="AND">AND</option>' +
                        '<option value="OR">OR</option>' +
                    '</select>' +
                '</div>'
            );
        }

        container.insertAdjacentHTML('beforeend', createRuleRow(index, type));

        var row = container.querySelector('.strategy-rule-row[data-index="' + index + '"]');
        refreshIndicator(row);
        buildValue(index, row);
    };

    document.addEventListener('change', function (event) {
        var target = event.target;

        if (target.classList.contains('rule-indicator')) {
            refreshIndicator(target.closest('.strategy-rule-row'));
        }

        if (target.classList.contains('rule-value-type')) {
            var row = target.closest('.strategy-rule-row');
            buildValue(row.getAttribute('data-index'), row);
        }

        if (target.classList.contains('rule-value-indicator')) {
            refreshValueIndicator(target.closest('.strategy-rule-row'));
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.classList.contains('strategy-rule-remove')) {
            return;
        }

        var row = event.target.closest('.strategy-rule-row');
        if (!row) {
            return;
        }

        var previous = row.previousElementSibling;

        if (previous && previous.classList.contains('rule-logical')) {
            previous.remove();
        } else {
            var next = row.nextElementSibling;

            if (next && next.classList.contains('rule-logical')) {
                next.remove();
            }
        }

        row.remove();
    });
})();
</script>

@endsection
