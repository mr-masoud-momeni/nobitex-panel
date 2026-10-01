@extends('Backend.layouts.Master')

@section('content')
<div class="row">
    <div class="col-lg-12">
        @include('Backend.layouts.errors')

        <div class="panel panel-default">
            <div class="panel-heading"><h3>ویرایش کاربر</h3></div>
            <div class="panel-body">
                <form action="{{ route('register.update', $user->id) }}" method="post">
                    @csrf
                    @method('PATCH')

                    <div class="form-group">
                        <label>نام</label>
                        <input type="text" name="name" class="form-control" value="{{ $user->name }}" required>
                    </div>

                    <div class="form-group">
                        <label>شماره همراه</label>
                        <input type="text" name="phone" class="form-control" value="{{ $user->phone }}" dir="ltr">
                    </div>

                    <div class="form-group">
                        <label>ایمیل</label>
                        <input type="email" name="email" class="form-control" value="{{ $user->email }}" required dir="ltr">
                    </div>

                    <div class="form-group">
                        <label>رمز عبور جدید</label>
                        <input type="password" name="password" class="form-control" autocomplete="new-password">
                    </div>

                    <div class="form-group">
                        <label>تکرار رمز عبور جدید</label>
                        <input type="password" name="password_confirmation" class="form-control" autocomplete="new-password">
                    </div>

                    <div class="form-group">
                        <label>نقش‌ها</label><br>
                        @foreach($roles as $role)
                            <label class="checkbox-inline">
                                <input type="checkbox" name="role[]" value="{{ $role->id }}"
                                    {{ $user->roles->contains('id', $role->id) ? 'checked' : '' }}>
                                {{ $role->name }}
                            </label>
                        @endforeach
                    </div>

                    <div class="form-group">
                        <label>سطح دسترسی</label><br>
                        @foreach($permissions as $permission)
                            <label class="checkbox-inline">
                                <input type="checkbox" name="permission[]" value="{{ $permission->id }}"
                                    {{ $user->permissions->contains('id', $permission->id) ? 'checked' : '' }}>
                                {{ $permission->name }}
                            </label>
                        @endforeach
                    </div>

                    <button type="submit" class="btn btn-success">ذخیره تغییرات</button>
                    <a href="{{ route('register.index') }}" class="btn btn-default">انصراف</a>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
