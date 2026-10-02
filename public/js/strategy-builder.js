document.addEventListener('DOMContentLoaded', function () {
    var ruleIndex = 0;

    var indicators = {
        price: {
            label: 'قیمت',
            parameters: [],
            compareWith: ['price', 'ema', 'sma'],
            operators: ['>', '<', '>=', '<=', '=', 'crosses_above', 'crosses_below', 'breaks_above_without_touch', 'breaks_below_without_touch', 'slope_>', 'slope_<']
        },
        ema: {
            label: 'EMA',
            parameters: ['period'],
            compareWith: ['price', 'ema', 'sma'],
            operators: ['>', '<', '>=', '<=', '=', 'crosses_above', 'crosses_below', 'slope_>', 'slope_<']
        },
        sma: {
            label: 'SMA',
            parameters: ['period'],
            compareWith: ['price', 'ema', 'sma'],
            operators: ['>', '<', '>=', '<=', '=', 'crosses_above', 'crosses_below', 'slope_>', 'slope_<']
        },
        rsi: {
            label: 'RSI',
            parameters: ['period'],
            compareWith: ['rsi'],
            operators: ['>', '<', '>=', '<=', '=', 'slope_>', 'slope_<']
        },
        macd: {
            label: 'MACD',
            parameters: ['fast', 'slow', 'signal'],
            compareWith: ['macd'],
            operators: ['>', '<', '>=', '<=', '=']
        },
        volume: {
            label: 'حجم',
            parameters: [],
            compareWith: ['volume'],
            operators: ['>', '<', '>=', '<=', '=']
        }
    };

    var operatorLabels = {
        '>': 'بزرگ‌تر از',
        '<': 'کوچک‌تر از',
        '>=': 'بزرگ‌تر یا مساوی',
        '<=': 'کوچک‌تر یا مساوی',
        '=': 'مساوی',
        'crosses_above': 'عبور رو به بالا از',
        'crosses_below': 'عبور رو به پایین از',
        'breaks_above_without_touch': 'شکست کامل رو به بالا بدون برخورد',
        'breaks_below_without_touch': 'شکست کامل رو به پایین بدون برخورد',
        'slope_>': 'شیب بیشتر از',
        'slope_<': 'شیب کمتر از'
    };

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getIndicatorOptions(allowed) {
        return allowed.map(function (key) {
            return '<option value="' + escapeHtml(key) + '">' +
                escapeHtml(indicators[key].label) +
                '</option>';
        }).join('');
    }

    function getOperatorOptions(indicatorKey) {
        return indicators[indicatorKey].operators.map(function (operator) {
            return '<option value="' + escapeHtml(operator) + '">' +
                escapeHtml(operatorLabels[operator]) +
                '</option>';
        }).join('');
    }

    function getParameterInputs(index, fieldName, indicatorKey, includeLookback) {
        var parameters = indicators[indicatorKey].parameters.slice();

        if (includeLookback) {
            parameters.push('lookback');
        }

        if (!parameters.length) {
            return '<span class="text-muted">بدون پارامتر</span>';
        }

        return parameters.map(function (parameter) {
            var defaultValue = parameter === 'period' ? 14 :
                parameter === 'lookback' ? 5 :
                parameter === 'fast' ? 12 :
                parameter === 'slow' ? 26 : 9;

            var label = parameter === 'period' ? 'دوره' :
                parameter === 'lookback' ? 'تعداد کندل' :
                parameter === 'fast' ? 'سریع' :
                parameter === 'slow' ? 'کند' : 'سیگنال';

            return '<div class="strategy-parameter">' +
                '<label>' + escapeHtml(label) + '</label>' +
                '<input type="number" min="1" step="1" ' +
                    'name="rules[' + index + '][' + fieldName + '][' + parameter + ']" ' +
                    'class="form-control input-sm" value="' + defaultValue + '">' +
                '</div>';
        }).join('');
    }

    function isSlopeOperator(operator) {
        return operator === 'slope_>' || operator === 'slope_<';
    }

    function refreshSlopeParameters(row) {
        var index = row.getAttribute('data-index');
        var indicatorKey = row.querySelector('.rule-indicator').value;
        var container = row.querySelector('.rule-parameters');
        var operator = row.querySelector('.rule-operator').value;

        container.innerHTML = getParameterInputs(index, 'parameters', indicatorKey, isSlopeOperator(operator));
    }

    function refreshSourceIndicator(row) {
        var index = row.getAttribute('data-index');
        var indicatorKey = row.querySelector('.rule-indicator').value;

        row.querySelector('.rule-parameters').innerHTML =
            getParameterInputs(index, 'parameters', indicatorKey, false);

        row.querySelector('.rule-operator').innerHTML =
            getOperatorOptions(indicatorKey);

        refreshSlopeParameters(row);
        refreshComparisonOptions(row);
    }

    function refreshComparisonOptions(row) {
        var sourceIndicator = row.querySelector('.rule-indicator').value;
        var operator = row.querySelector('.rule-operator').value;
        var allowedIndicators = indicators[sourceIndicator].compareWith;
        var typeSelect = row.querySelector('.rule-value-type');
        var currentType = typeSelect.value;

        var canCompareWithIndicator = allowedIndicators.length > 0 && !isSlopeOperator(operator);

        typeSelect.innerHTML =
            '<option value="number">عدد</option>' +
            (canCompareWithIndicator ? '<option value="indicator">اندیکاتور</option>' : '');

        if (currentType === 'indicator' && canCompareWithIndicator) {
            typeSelect.value = 'indicator';
        } else {
            typeSelect.value = 'number';
        }

        refreshComparisonValue(row);
    }

    function refreshComparisonValue(row) {
        var index = row.getAttribute('data-index');
        var sourceIndicator = row.querySelector('.rule-indicator').value;
        var valueType = row.querySelector('.rule-value-type').value;
        var container = row.querySelector('.rule-value');

        if (valueType === 'number') {
            container.innerHTML =
                '<input type="number" step="any" ' +
                'name="rules[' + index + '][value]" ' +
                'class="form-control" placeholder="مثلاً 70">';

            return;
        }

        var allowedIndicators = indicators[sourceIndicator].compareWith;

        container.innerHTML =
            '<div class="strategy-comparison-indicator">' +
                '<select name="rules[' + index + '][value][indicator]" ' +
                    'class="form-control rule-value-indicator">' +
                    getIndicatorOptions(allowedIndicators) +
                '</select>' +
                '<div class="rule-value-parameters"></div>' +
            '</div>';

        refreshValueIndicator(row);
    }

    function refreshValueIndicator(row) {
        var index = row.getAttribute('data-index');
        var indicatorKey = row.querySelector('.rule-value-indicator').value;

        row.querySelector('.rule-value-parameters').innerHTML =
            getParameterInputs(index, 'value][parameters', indicatorKey, false);
    }

    function createRuleRow(index, type) {
        return '' +
            '<div class="strategy-rule-row" data-index="' + index + '">' +
                '<input type="hidden" name="rules[' + index + '][type]" value="' + escapeHtml(type) + '">' +
                '<input type="hidden" name="rules[' + index + '][sort_order]" value="' + index + '">' +

                '<div class="strategy-rule-line">' +
                    '<div class="strategy-rule-field strategy-rule-source">' +
                        '<label>اندیکاتور</label>' +
                        '<select name="rules[' + index + '][indicator]" class="form-control rule-indicator">' +
                            getIndicatorOptions(Object.keys(indicators)) +
                        '</select>' +
                    '</div>' +

                    '<div class="strategy-rule-field strategy-rule-source-parameters">' +
                        '<label>پارامتر</label>' +
                        '<div class="rule-parameters"></div>' +
                    '</div>' +
                '</div>' +

                '<div class="strategy-rule-line strategy-rule-operator-line">' +
                    '<div class="strategy-rule-field">' +
                        '<label>عملگر</label>' +
                        '<select name="rules[' + index + '][operator]" class="form-control rule-operator"></select>' +
                    '</div>' +
                '</div>' +

                '<div class="strategy-rule-line">' +
                    '<div class="strategy-rule-field strategy-rule-value-type">' +
                        '<label>مقایسه با</label>' +
                        '<select name="rules[' + index + '][value_type]" class="form-control rule-value-type">' +
                            '<option value="number">عدد</option>' +
                            '<option value="indicator">اندیکاتور</option>' +
                        '</select>' +
                    '</div>' +

                    '<div class="strategy-rule-field strategy-rule-value-field">' +
                        '<label>مقدار</label>' +
                        '<div class="rule-value"></div>' +
                    '</div>' +

                    '<button type="button" class="btn btn-danger strategy-rule-remove" title="حذف شرط">×</button>' +
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
        refreshSourceIndicator(row);
    };

    document.addEventListener('change', function (event) {
        var target = event.target;
        var row = target.closest('.strategy-rule-row');

        if (!row) {
            return;
        }

        if (target.classList.contains('rule-indicator')) {
            refreshSourceIndicator(row);
        }

        if (target.classList.contains('rule-value-type')) {
            refreshComparisonValue(row);
        }

        if (target.classList.contains('rule-operator')) {
            refreshSlopeParameters(row);
            refreshComparisonOptions(row);
        }

        if (target.classList.contains('rule-value-indicator')) {
            refreshValueIndicator(row);
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
        var next = row.nextElementSibling;

        if (previous && previous.classList.contains('rule-logical')) {
            previous.remove();
        } else if (next && next.classList.contains('rule-logical')) {
            next.remove();
        }

        row.remove();
    });

    function setParameterValues(container, values) {
        Object.keys(values || {}).forEach(function (key) {
            var input = container.querySelector('input[name*="[' + key + ']"]');
            if (input) {
                input.value = values[key];
            }
        });
    }

    function restoreInitialRules() {
        if (!Array.isArray(window.strategyInitialRules) || !window.strategyInitialRules.length) {
            return;
        }

        window.strategyInitialRules.forEach(function (rule) {
            addRule(rule.type);

            var container = document.getElementById(rule.type + '-rules');
            var rows = container.querySelectorAll('.strategy-rule-row');
            var row = rows[rows.length - 1];

            if (!row) {
                return;
            }

            var indicator = row.querySelector('.rule-indicator');
            indicator.value = rule.indicator;
            refreshSourceIndicator(row);

            setParameterValues(row.querySelector('.rule-parameters'), rule.parameters || {});

            var operator = row.querySelector('.rule-operator');
            operator.value = rule.operator;

            var valueType = row.querySelector('.rule-value-type');
            valueType.value = rule.value_type || 'number';
            refreshComparisonValue(row);

            if (rule.value_type === 'indicator' && rule.value && typeof rule.value === 'object') {
                var valueIndicator = row.querySelector('.rule-value-indicator');

                if (valueIndicator && rule.value.indicator) {
                    valueIndicator.value = rule.value.indicator;
                    refreshValueIndicator(row);
                    setParameterValues(
                        row.querySelector('.rule-value-parameters'),
                        rule.value.parameters || {}
                    );
                }
            } else if (rule.value !== null && rule.value !== undefined) {
                var valueInput = row.querySelector('.rule-value input');
                if (valueInput) {
                    valueInput.value = rule.value;
                }
            }

            var logicals = container.querySelectorAll('.rule-logical select');
            if (logicals.length && rule.logical_operator) {
                logicals[logicals.length - 1].value = rule.logical_operator;
            }
        });
    }

    restoreInitialRules();
});
