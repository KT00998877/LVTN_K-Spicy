<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
    public function up(): void {
        Schema::table('blogs', function(Blueprint $t) { $t->string('news_section',20)->default('NEWS')->index(); });
        DB::table('blogs')->where('category','PROMOTION')->update(['news_section'=>'PROMOTION']);
        Schema::create('contact_messages', function(Blueprint $t) { $t->id(); $t->string('name',100); $t->string('email',150); $t->string('phone',20)->nullable(); $t->string('address',255)->nullable(); $t->string('topic',30); $t->text('message'); $t->timestamps(); });
    }
    public function down(): void { Schema::dropIfExists('contact_messages'); Schema::table('blogs',fn(Blueprint $t)=>$t->dropColumn('news_section')); }
};
