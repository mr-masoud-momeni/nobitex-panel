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
                <form action="{{ route('strategy.store') }}" method="post">@csrf
<div class="form-group"><label>نام استراتژی</label><input type="text" name="name" class="form-control" required></div>
<div class="form-group"><label>توضیحات</label><textarea name="description" class="form-control" rows="3"></textarea></div>
<div class="panel panel-default"><div class="panel-heading clearfix"><strong>شرایط ورود</strong><button type="button" class="btn btn-primary btn-xs pull-left" onclick="addRule('entry')">+ افزودن شرط</button></div><div class="panel-body" id="entry-rules"></div></div>
<div class="panel panel-default"><div class="panel-heading clearfix"><strong>شرایط خروج</strong><button type="button" class="btn btn-primary btn-xs pull-left" onclick="addRule('exit')">+ افزودن شرط</button></div><div class="panel-body" id="exit-rules"></div></div>
<div class="panel panel-default"><div class="panel-heading">مدیریت معامله</div><div class="panel-body"><input type="number" name="risk_percent" class="form-control" placeholder="ریسک %"><br><input type="number" name="stop_loss" class="form-control" placeholder="حد ضرر %"><br><input type="number" name="take_profit" class="form-control" placeholder="حد سود %"><br><label><input type="checkbox" name="is_active" value="1" checked> فعال</label></div></div>
<button type="submit" class="btn btn-success">ذخیره استراتژی</button> <a href="{{ route('strategy.index') }}" class="btn btn-default">انصراف</a>
</form>
            </div>
        </div>
    </div>
</div>
<script>
(function(){let n=0;const I={price:['قیمت',[]],ema:['EMA',['period']],sma:['SMA',['period']],rsi:['RSI',['period']],macd:['MACD',['fast','slow','signal']],volume:['حجم',[]]};const O=[['>','بزرگ‌تر از'],['<','کوچک‌تر از'],['>=','بزرگ‌تر یا مساوی'],['<=','کوچک‌تر یا مساوی'],['=','مساوی'],['crosses_above','عبور رو به بالا از'],['crosses_below','عبور رو به پایین از']];
window.addRule=function(t){let b=document.getElementById(t+'-rules'),i=n++;if(b.querySelector('.strategy-rule-row'))b.insertAdjacentHTML('beforeend','<div class="rule-logical"><select name="rules['+i+'][logical_operator]"><option>AND</option><option>OR</option></select></div>');b.insertAdjacentHTML('beforeend',row(i,t));refresh(b.lastElementChild)};function row(i,t){return '<div class="strategy-rule-row" style="background:#f8f8f8;border:1px solid #ddd;padding:15px;margin-bottom:10px"><input type="hidden" name="rules['+i+'][type]" value="'+t+'"><input type="hidden" name="rules['+i+'][sort_order]" value="'+i+'"><div class="row"><div class="col-md-4"><label>اندیکاتور</label><select name="rules['+i+'][indicator]" class="form-control ri">'+Object.keys(I).map(k=>'<option value="'+k+'">'+I[k][0]+'</option>').join('')+'</select></div><div class="col-md-4"><label>پارامتر</label><div class="rp"></div></div><div class="col-md-4"><label>عملگر</label><select name="rules['+i+'][operator]" class="form-control">'+O.map(o=>'<option value="'+o[0]+'">'+o[1]+'</option>').join('')+'</select></div><div class="col-md-4"><label>نوع مقدار</label><select name="rules['+i+'][value_type]" class="form-control vt"><option value="number">عدد</option><option value="indicator">اندیکاتور</option></select></div><div class="col-md-7"><label>مقدار / اندیکاتور دوم</label><div class="rv"></div></div><div class="col-md-1"><label>&nbsp;</label><button type="button" class="btn btn-danger rm">×</button></div></div></div>'}function p(a,i,n){return a.map(x=>'<input type="number" min="1" class="form-control input-sm" name="rules['+i+']['+n+']['+x+']" placeholder="'+x+'" value="'+(x==='period'?14:x==='fast'?12:x==='slow'?26:9)+'">').join('')}function refresh(r){let i=r.dataset.index, m=I[r.querySelector('.ri').value];r.querySelector('.rp').innerHTML=p(m[1],i,'parameters');val(r)}function val(r){let i=r.dataset.index;if(r.querySelector('.vt').value==='number'){r.querySelector('.rv').innerHTML='<input type="number" step="any" name="rules['+i+'][value]" class="form-control" placeholder="مثلاً 70">';return}r.querySelector('.rv').innerHTML='<select name="rules['+i+'][value][indicator]" class="form-control vi">'+Object.keys(I).map(k=>'<option value="'+k+'">'+I[k][0]+'</option>').join('')+'</select><div class="vp"></div>';vp(r)}function vp(r){let i=r.dataset.index,m=I[r.querySelector('.vi').value];r.querySelector('.vp').innerHTML=p(m[1],i,'value][parameters')}document.addEventListener('change',e=>{if(e.target.classList.contains('ri'))refresh(e.target.closest('.strategy-rule-row'));if(e.target.classList.contains('vt'))val(e.target.closest('.strategy-rule-row'));if(e.target.classList.contains('vi'))vp(e.target.closest('.strategy-rule-row'))});document.addEventListener('click',e=>{if(!e.target.classList.contains('rm'))return;let r=e.target.closest('.strategy-rule-row'),p=r.previousElementSibling;if(p&&p.classList.contains('rule-logical'))p.remove();r.remove()})})();
</script>\n@endsection
