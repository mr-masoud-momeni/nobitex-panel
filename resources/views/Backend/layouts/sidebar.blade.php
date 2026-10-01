<div id="sidebar-wrapper">
    <ul class="sidebar-nav">
        <li>
            <a href="{{ route('admin.dashboard') }}">داشبورد</a>
        </li>

        <li class="dropdown {{ Request::is('admin/Permission') || Request::is('admin/Role') || Request::is('admin/register*') ? 'open' : '' }}">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown">کاربران<span class="caret"></span></a>
            <ul class="dropdown-menu" role="menu">
                <li><a href="{{ route('Permission.index') }}">سطح دسترسی کاربران</a></li>
                <li><a href="{{ route('role.index') }}">نقش کاربر</a></li>
                <li><a href="{{ route('register.index') }}">مدیریت کاربران</a></li>
            </ul>
        </li>

        <li class="dropdown {{ Request::is('admin/menu*') ? 'open' : '' }}">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown">منو<span class="caret"></span></a>
            <ul class="dropdown-menu" role="menu">
                <li><a href="{{ route('menu.index') }}">مدیریت منو</a></li>
            </ul>
        </li>

        <li class="{{ Request::is('admin/strategy*') ? 'active' : '' }}">
            <a href="{{ route('strategy.index') }}">استراتژی‌های معاملاتی</a>
        </li>

        <li class="{{ Request::is('admin/trade*') ? 'active' : '' }}">
            <a href="{{ route('trade.index') }}">معاملات</a>
        </li>

        <li class="dropdown {{ Request::is('admin/email*') ? 'open' : '' }}">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown">اطلاع‌رسانی<span class="caret"></span></a>
            <ul class="dropdown-menu" role="menu">
                <li><a href="{{ route('email.index') }}">ایمیل</a></li>
                <li><a href="{{ route('email-group.create') }}">دسته‌بندی ایمیل</a></li>
            </ul>
        </li>
    </ul>
</div>