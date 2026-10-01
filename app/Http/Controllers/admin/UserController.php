<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with(['roles', 'permissions'])->orderBy('id')->get();
        $permissions = Permission::orderBy('id')->get();
        $roles = Role::orderBy('id')->get();

        return view('Backend.auth.register', compact('users', 'permissions', 'roles'));
    }

    public function create()
    {
        return redirect()->route('register.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ]);

        $data['password'] = Hash::make($data['password'] ?: bin2hex(random_bytes(8)));

        $user = User::create($data);

        if ($request->filled('permission')) {
            $user->syncPermissions($request->input('permission'));
        }

        if ($request->filled('role')) {
            $user->syncRoles($request->input('role'));
        }

        return redirect()
            ->route('register.index')
            ->with('success', 'کاربر با موفقیت ایجاد شد.');
    }

    public function regeneratePassword($uuid)
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        $password = bin2hex(random_bytes(8));

        $user->update(['password' => Hash::make($password)]);

        return redirect()
            ->route('register.index')
            ->with('generated_password', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $password,
            ]);
    }

    public function show($id)
    {
        return redirect()->route('register.edit', $id);
    }

    public function edit($uuid)
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        $permissions = Permission::orderBy('id')->get();
        $roles = Role::orderBy('id')->get();

        return view('Backend.auth.EditUser', compact('user', 'permissions', 'roles'));
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone,' . $id],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $id],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);
        $user->syncPermissions($request->input('permission', []));
        $user->syncRoles($request->input('role', []));

        return redirect()
            ->route('register.index')
            ->with('success', 'کاربر با موفقیت ویرایش شد.');
    }

    public function destroy($id)
    {
        User::findOrFail($id)->delete();

        return redirect()
            ->route('register.index')
            ->with('success', 'کاربر حذف شد.');
    }
}
