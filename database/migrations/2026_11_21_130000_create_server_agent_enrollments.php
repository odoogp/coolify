<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instance_settings')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('instance_settings', 'cloudflare_api_token')) {
                    $table->text('cloudflare_api_token')->nullable();
                }
                if (! Schema::hasColumn('instance_settings', 'cloudflare_account_id')) {
                    $table->string('cloudflare_account_id')->nullable();
                }
                if (! Schema::hasColumn('instance_settings', 'cloudflare_zone_id')) {
                    $table->string('cloudflare_zone_id')->nullable();
                }
                if (! Schema::hasColumn('instance_settings', 'cloudflare_tunnel_domain')) {
                    $table->string('cloudflare_tunnel_domain')->nullable();
                }
            });
        }

        if (Schema::hasTable('servers') && ! Schema::hasColumn('servers', 'public_ip')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->string('public_ip')->nullable();
            });
        }

        if (! Schema::hasTable('server_agent_enrollments')) {
            Schema::create('server_agent_enrollments', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('token_hash', 64)->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('hostname');
                $table->string('cloudflare_tunnel_id')->nullable();
                $table->string('cloudflare_dns_record_id')->nullable();
                $table->text('tunnel_token')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('expires_at');
                $table->timestamp('reported_at')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_agent_enrollments');

        if (Schema::hasTable('servers') && Schema::hasColumn('servers', 'public_ip')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('public_ip');
            });
        }

        if (Schema::hasTable('instance_settings')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                foreach (['cloudflare_api_token', 'cloudflare_account_id', 'cloudflare_zone_id', 'cloudflare_tunnel_domain'] as $column) {
                    if (Schema::hasColumn('instance_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
