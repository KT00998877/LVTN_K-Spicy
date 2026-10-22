<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        if (!DB::table('roles')->where('code', 'SHIPPER')->exists()) DB::table('roles')->insert(['code'=>'SHIPPER','name'=>'Giao hàng','description'=>'Nhân viên giao hàng','created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void {
        $role = DB::table('roles')->where('code','SHIPPER')->first();
        if ($role && !DB::table('user_role')->where('role_id',$role->id)->exists()) DB::table('roles')->where('id',$role->id)->delete();
    }
};
