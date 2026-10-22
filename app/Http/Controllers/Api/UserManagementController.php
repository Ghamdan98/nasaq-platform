<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    private function access(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    private function serialize(User $user): array
    {
        return array_merge($user->only(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at', 'updated_at']), ['effective_permissions' => $user->effectivePermissions()]);
    }

    public function permissions(Request $request)
    {
        $this->access($request);

        return response()->json(['groups' => UserPermissions::groups(), 'roles' => ['admin' => 'مدير النظام', 'hr' => 'موارد بشرية', 'reviewer' => 'مراجع'], 'defaults' => ['admin' => UserPermissions::defaults('admin'), 'hr' => UserPermissions::defaults('hr'), 'reviewer' => UserPermissions::defaults('reviewer')]])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request)
    {
        $this->access($request);
        $data = $request->validate(['search' => 'nullable|string|max:150', 'role' => 'nullable|in:admin,hr,reviewer', 'status' => 'nullable|in:active,inactive']);
        $records = User::query()->where('role','!=','developer')->when($data['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))->when($data['role'] ?? null, fn ($q, $role) => $q->where('role', $role))->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderBy('id')->paginate(app(\App\Services\SystemSettingsService::class)->pageSize());
        $records->setCollection($records->getCollection()->map(fn ($user) => $this->serialize($user)));

        return response()->json($records)->header('Cache-Control', 'no-store');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => 'nullable|string|max:30', 'role' => 'required|in:admin,hr,reviewer', 'status' => 'required|in:active,inactive',
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:128', 'confirmed'],
            'permissions' => 'sometimes|array', 'permissions.*' => ['required', 'string', 'distinct', Rule::in(UserPermissions::names())],
            'reason' => 'required|string|min:5|max:2000',
        ], ['password.min' => 'كلمة المرور يجب أن تتكون من 12 حرفًا على الأقل.', 'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.', 'email.unique' => 'البريد الإلكتروني مستخدم لحساب آخر.', 'reason.min' => 'أدخل سببًا واضحًا للتغيير من خمسة أحرف على الأقل.']);
        if ($data['role'] === 'reviewer' && in_array('adjust_transaction_values', $data['permissions'] ?? [], true)) {
            throw ValidationException::withMessages(['permissions' => 'دور المراجع لا يسمح بتعديل القيم المحسوبة. اختر دور الموارد البشرية لهذه المهمة.']);
        }
        if ($data['role'] !== 'admin' && in_array('manage_employees', $data['permissions'] ?? [], true) && ! in_array('view_employee_sensitive', $data['permissions'] ?? [], true)) {
            throw ValidationException::withMessages(['permissions' => 'إدارة الموظفين تتطلب صلاحية عرض بياناتهم الحساسة.']);
        }

        return $data;
    }

    private function lockAdmins(Request $request)
    {
        // Serialize administrator mutations and recheck the acting administrator after acquiring locks.
        $admins = User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
        abort_unless($admins->contains(fn ($admin) => $admin->id === $request->user()->id && $admin->status === 'active'), 403);

        return $admins;
    }

    private function attributes(array $data, ?User $existing = null): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'role', 'status']));
        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }
        if (array_key_exists('permissions', $data) || ! $existing || $existing->role !== $data['role'] || $data['role'] === 'admin') {
            $defaults = UserPermissions::defaults($data['role']);
            $selected = $data['role'] === 'admin' ? $defaults : ($data['permissions'] ?? $defaults);
            $attributes['permissions_json'] = array_values(array_diff($selected, $defaults));
            $attributes['denied_permissions_json'] = array_values(array_diff($defaults, $selected));
        }

        return $attributes;
    }

    private function log(Request $request, User $user, string $action, ?array $before, string $reason): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'entity_type' => User::class, 'entity_id' => $user->id,
            'old_values_json' => $before, 'new_values_json' => ['user' => $this->serialize($user), 'reason' => $reason], 'ip_address' => $request->ip()]);
    }

    public function store(Request $request)
    {
        $this->access($request);
        $data = $this->validated($request);

        return DB::transaction(function () use ($request, $data) {
            $this->lockAdmins($request);
            $user = User::create($this->attributes($data));
            $this->log($request, $user, 'user_created', null, $data['reason']);

            return response()->json(['user' => $this->serialize($user), 'message' => 'تم إنشاء الحساب. لم يتم تسجيل دخوله تلقائيًا.'], 201);
        });
    }

    public function update(Request $request, User $user)
    {
        $this->access($request);
        abort_if($user->role === 'developer',403);
        $data = $this->validated($request, $user);

        return DB::transaction(function () use ($request, $user, $data) {
            $admins = $this->lockAdmins($request);
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($user->isAdmin() && $user->status === 'active' && ($data['role'] !== 'admin' || $data['status'] !== 'active') && $admins->where('status', 'active')->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'لا يمكن تعطيل آخر مدير نشط أو تغيير دوره. أنشئ مديرًا نشطًا آخر أولًا.']);
            }
            if ($user->id === $request->user()->id && ($data['role'] !== 'admin' || $data['status'] !== 'active')) {
                throw ValidationException::withMessages(['status' => 'لا يمكنك تعطيل حسابك أو تغيير دورك الحالي. استخدم حساب مدير آخر.']);
            }
            $before = $this->serialize($user);
            $user->fill($this->attributes($data, $user));
            $invalidate = $user->isDirty(['password', 'email', 'role', 'status', 'permissions_json', 'denied_permissions_json']);
            $user->save();
            if ($invalidate) {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $this->log($request, $user, 'user_updated', $before, $data['reason']);
            if (! empty($data['password'])) {
                $this->log($request, $user, 'user_password_reset', null, $data['reason']);
            }

            return response()->json(['user' => $this->serialize($user), 'reauthentication_required' => $invalidate && $user->id === $request->user()->id, 'message' => 'تم حفظ الحساب. أُلغيت جلساته السابقة إذا تغيرت بيانات الدخول أو الصلاحيات.']);
        });
    }

    public function audit(Request $request, User $user)
    {
        $this->access($request);
        abort_if($user->role === 'developer',403);

        return response()->json(AuditLog::with('user:id,name')->where('entity_type', User::class)->where('entity_id', $user->id)->latest('id')->paginate(15))->header('Cache-Control', 'no-store');
    }
}
