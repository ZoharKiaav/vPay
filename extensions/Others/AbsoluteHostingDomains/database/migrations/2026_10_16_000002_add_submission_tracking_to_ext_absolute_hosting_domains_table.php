<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'ext_absolute_hosting_domains',
            function (Blueprint $table): void {
                $table->string('provider_reference')
                    ->nullable()
                    ->after('provider_status');

                $table->dateTime('submission_started_at')
                    ->nullable()
                    ->after('provider_reference');

                $table->dateTime('submission_confirmed_at')
                    ->nullable()
                    ->after('submission_started_at');

                $table->index(
                    [
                        'provider_status',
                        'submission_started_at',
                    ],
                    'ext_ah_domains_submission_state_index',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'ext_absolute_hosting_domains',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'ext_ah_domains_submission_state_index',
                );

                $table->dropColumn([
                    'provider_reference',
                    'submission_started_at',
                    'submission_confirmed_at',
                ]);
            },
        );
    }
};