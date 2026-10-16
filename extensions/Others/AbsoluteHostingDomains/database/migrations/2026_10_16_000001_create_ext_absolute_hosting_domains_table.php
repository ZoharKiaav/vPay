<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_absolute_hosting_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Service::class)
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('domain_name')->unique();
            $table->string('tld', 63);
            $table->string('status')->default('pending');
            $table->string('provider_status')->nullable();
            $table->unsignedSmallInteger('registration_period')->default(1);
            $table->date('expires_at')->nullable();
            $table->boolean('transfer_locked')->default(false);
            $table->json('nameservers')->nullable();
            $table->longText('contact_data')->nullable();
            $table->json('provider_data')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_absolute_hosting_domains');
    }
};
