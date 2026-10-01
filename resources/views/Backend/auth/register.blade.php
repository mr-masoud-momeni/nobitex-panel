@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(session('generated_password'))
            <div class="alert alert-info">
                <strong>رمز جدید:</strong>
                <code>{{ session('generated_password.password') }}</code>
                <span> — این رمز را ذخیره کنید.</span>
            </div>
        @endif

        <div class="panel panel-default">
            <div class="panel-heading"><h3>مدیریت کاربران</h3></div>
            <div class="panel-body">
                <form action="{{ route('register.store') }}" method="post">
                    @csrf

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>نام</label>
                                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                            </div>

                            <div class="form-group">
                                <label>شماره همراه</label>
                                <input type="text" name="phone" class="form-control" value="{{ old('phone') }}" dir="ltr">
                            </div>

                            <div class="form-group">
                                <label>ایمیل</label>
                                <input type="email" name="email" class="form-control" value="{{ old('email') }}" required dir="ltr">
                            </div>

                            <div class="form-group">
                                <label>رمز عبور</label>
                                <input type="password" name="password" class="form-control" autocomplete="new-password">
                            </div>

                            <div class="form-group">
                                <label>تکرار رمز عبور</label>
                                <input type="password" name="password_confirmation" class="form-control" autocomplete="new-password">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <h4>نقش</h4>
                            @foreach($roles as $role)
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="role[]" value="{{ $role->id }}">
                                    {{ $role->name }}
                                </label>
                            @endforeach

                            <h4 style="margin-top:25px;">سطح دسترسی</h4>
                            @foreach($permissions as $permission)
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="permission[]" value="{{ $permission->id }}">
                                    {{ $permission->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success">ایجاد کاربر</button>
                </form>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><h3>کاربران</h3></div>
            <div class="panel-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>نام</th>
                            <th>ایمیل</th>
                            <th>موبایل</th>
                            <th>نقش‌ها</th>
                            <th width="100">عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($users as $user)
                            <tr>
                                <td>{{ $user->name }}</td>
                                <td dir="ltr">{{ $user->email }}</td>
                                <td dir="ltr">{{ $user->phone ?: '—' }}</td>
                                <td>{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                                <td>
                                    <a href="{{ route('register.edit', $user->uuid) }}" class="btn btn-xs btn-primary">ویرایش</a>
                                    <form action="{{ route('register.destroy', $user->id) }}" method="post" style="display:inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('کاربر حذف شود؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
