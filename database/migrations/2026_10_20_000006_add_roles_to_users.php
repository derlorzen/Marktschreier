<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('staff')->after('password');   // admin | staff
            $table->boolean('active')->default(true)->after('role');
            $table->boolean('must_change_password')->default(false)->after('active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
        });
        // Bestehende Benutzer stammen aus der Einrichtung und werden Administratoren.
        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'active', 'must_change_password', 'last_login_at']);
        });
    }
};
