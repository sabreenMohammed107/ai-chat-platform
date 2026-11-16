<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('monthly_messages');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('model')->nullable();
            $table->unsignedInteger('max_pages')->default(100);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website')->nullable();
            $table->string('status')->default('active'); // active | suspended
            $table->string('assistant_name')->nullable();
            $table->text('instructions')->nullable();
            $table->text('contact_info')->nullable();
            $table->date('warned_for')->nullable(); // period start the quota email was sent for
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->foreignId('tenant_id')->nullable()->after('is_admin')->constrained()->cascadeOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // secret | public
            $table->string('name')->nullable();
            $table->string('prefix', 20);
            $table->string('hash', 64)->nullable()->unique(); // secret keys: sha256, the key itself is never stored
            $table->string('token', 80)->nullable()->unique(); // public keys: shown in the widget snippet anyway
            $table->json('allowed_domains')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // website | file | faq
            $table->string('title');
            $table->string('url', 2048)->nullable();
            $table->longText('content')->nullable(); // FAQ answer, or the text read from a file
            $table->string('status')->default('pending'); // pending | syncing | ready | failed
            $table->text('error')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048)->nullable();
            $table->string('title')->nullable();
            $table->text('content');
            $table->timestamps();
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('knowledge_chunks', fn (Blueprint $table) => $table->fullText(['title', 'content']));
        }

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('channel')->default('api'); // api | widget
            $table->string('user_ref')->nullable();
            $table->json('messages')->nullable();
            $table->unsignedInteger('user_messages')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'updated_at']);
        });

        Schema::create('usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->unique(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_sources');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('subscriptions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('is_admin');
        });
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
