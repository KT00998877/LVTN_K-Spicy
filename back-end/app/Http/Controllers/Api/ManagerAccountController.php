<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\{User, Role, AuthAuditLog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ManagerAccountController extends Controller
{
    public const ROLES = ['CUSTOMER' => 'Khách hàng', 'WAITER' => 'Phục vụ', 'KITCHEN' => 'Bếp', 'CASHIER' => 'Thu ngân', 'SHIPPER' => 'Giao hàng', 'MANAGER' => 'Quản lí'];
    private function row(User $u): array { return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_active' => $u->is_active, 'can_close_daily_stock' => (bool) $u->can_close_daily_stock, 'roles' => $u->roles->pluck('code'), 'created_at' => $u->created_at?->toIso8601String()]; }
    public function index(Request $request) {
        $data = $request->validate(['search' => ['nullable','string','max:150'], 'role' => ['nullable',Rule::in(array_keys(self::ROLES))], 'active' => ['nullable','in:0,1']]);
        $q = User::with('roles');
        if (!empty($data['search'])) $q->where(fn ($q) => $q->where('name','like','%'.$data['search'].'%')->orWhere('email','like','%'.$data['search'].'%')->orWhere('phone','like','%'.$data['search'].'%'));
        if (!empty($data['role'])) $q->whereHas('roles', fn ($q) => $q->where('code',$data['role']));
        if (isset($data['active'])) $q->where('is_active',(bool)$data['active']);
        $rows = $q->orderByDesc('id')->paginate(20);
        $rows->getCollection()->transform(fn ($u) => $this->row($u));
        return response()->json($rows);
    }
    public function save(Request $request, ?int $id = null) {
        $data = $request->validate(['name' => ['required','string','max:100'], 'email' => ['required','email','max:150',Rule::unique('users')->ignore($id)],
            'phone' => ['nullable','string','max:20',Rule::unique('users')->ignore($id)], 'is_active' => ['required','boolean'],
            'role' => ['required',Rule::in(array_keys(self::ROLES))], 'can_close_daily_stock' => ['sometimes', 'boolean'], 'password' => [$id ? 'nullable' : 'required','confirmed',Password::min(8)->letters()->numbers(),'max:128']]);
        return DB::transaction(function () use ($request,$data,$id) {
            // Serialize role changes, including concurrent attempts to remove managers.
            $manager = Role::firstOrCreate(['code'=>'MANAGER'],['name'=>self::ROLES['MANAGER']]);
            Role::whereKey($manager->id)->lockForUpdate()->first();
            $u = $id ? User::lockForUpdate()->findOrFail($id) : new User;
            $oldRoles = $id ? $u->roles()->pluck('code')->all() : [];
            if ($id === $request->user()->id && (!$data['is_active'] || $oldRoles !== [$data['role']])) throw ValidationException::withMessages(['role'=>'Không tự khóa hoặc thay đổi vai trò của tài khoản đang đăng nhập.']);
            if (in_array('MANAGER',$oldRoles) && (!$data['is_active'] || $data['role'] !== 'MANAGER') && !User::where('id','!=',$id)->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('code','MANAGER'))->exists()) throw ValidationException::withMessages(['role'=>'Phải giữ ít nhất một tài khoản quản lí hoạt động.']);
            $headChanged = (bool) $u->can_close_daily_stock !== ($data['role'] === 'KITCHEN' && (bool) ($data['can_close_daily_stock'] ?? false));
            $changed = $oldRoles !== [$data['role']];
            $emailChanged = $id && $u->email !== $data['email'];
            $u->fill(collect($data)->only(['name','email','phone','is_active'])->all());
            $u->can_close_daily_stock = $data['role'] === 'KITCHEN' && (bool) ($data['can_close_daily_stock'] ?? false);
            if (!empty($data['password'])) { $u->password = $data['password']; $u->password_changed_at = now(); $u->failed_login_count = 0; $u->locked_until = null; }
            if ($emailChanged) $u->email_verified_at = null;
            $u->save();
            $role = Role::firstOrCreate(['code'=>$data['role']],['name'=>self::ROLES[$data['role']]]);
            $u->roles()->sync([$role->id=>['assigned_at'=>now()]]);
            if ($changed || $headChanged || !$u->is_active || !empty($data['password']) || $emailChanged) $u->tokens()->delete();
            AuthAuditLog::create(['user_id'=>$u->id,'event'=>$id ? 'manager_account_updated' : 'manager_account_created','identifier'=>$u->email,'ip_address'=>$request->ip(),'success'=>true,
                'metadata'=>['actor_id'=>$request->user()->id,'roles_before'=>$oldRoles,'role_after'=>$data['role'],'active'=>$u->is_active,'password_reset'=>!empty($data['password'])]]);
            return response()->json(['data'=>$this->row($u->fresh('roles'))],$id ? 200 : 201);
        });
    }
    public function roles() {
        $scopes = ['SHIPPER'=>['Khu vực giao hàng riêng (chưa triển khai phân công đơn)'], 'CUSTOMER'=>['Thực đơn khách hàng','Đặt món trực tuyến','Tài khoản cá nhân'], 'WAITER'=>['Sơ đồ bàn','Đơn phục vụ','Thanh toán tại bàn','Yêu cầu xuất hóa đơn'], 'KITCHEN'=>['Hàng đợi món','Tiến độ chế biến','Nhắc phục vụ bưng món'], 'CASHIER'=>['Hóa đơn và thu tiền mang đi','Đặt bàn','Báo cáo doanh thu ngày'], 'MANAGER'=>['Tổng quan và báo cáo','Thực đơn','Kho và nguyên liệu','Đơn hàng và hóa đơn','Tài khoản và phân quyền']];
        return response()->json(['data'=>collect(self::ROLES)->map(fn($name,$code)=>['code'=>$code,'name'=>$name,'scopes'=>$scopes[$code],'users_count'=>User::whereHas('roles',fn($q)=>$q->where('code',$code))->count()])->values()]);
    }
}
