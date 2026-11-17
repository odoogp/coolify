<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('odoo_mail_daily_limit')->default(20);
        });

        Schema::table('gpsh_notice_settings', function (Blueprint $table) {
            $table->boolean('toast')->default(true);
            $table->unsignedTinyInteger('toast_seconds')->default(8);
        });

        Schema::create('gpsh_mail_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->date('sent_on');
            $table->unsignedInteger('sent')->default(0);
            $table->timestamps();
            $table->unique(['team_id', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpsh_mail_counts');

        Schema::table('gpsh_notice_settings', function (Blueprint $table) {
            $table->dropColumn(['toast', 'toast_seconds']);
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('odoo_mail_daily_limit');
        });
    }
};
