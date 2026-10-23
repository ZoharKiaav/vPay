<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'hosting_domain_service_links',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('hosting_service_id')
                    ->unique()
                    ->constrained('services')
                    ->cascadeOnDelete();

                $table->foreignId('domain_service_id')
                    ->unique()
                    ->constrained('services')
                    ->cascadeOnDelete();

                $table->timestamps();
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'hosting_domain_service_links',
        );
    }
};
