<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            $t->string('note', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $t) => $t->dropColumn('note'));
    }
};
