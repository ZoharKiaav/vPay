<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $key = 'vkloud_service_fulfilled';
        $data = EmailTemplateSeeder::mapping[$key];

        DB::table('notification_templates')->insertOrIgnore(
            array_merge(
                $data,
                [
                    'key' => $key,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ),
        );
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->where('key', 'vkloud_service_fulfilled')
            ->delete();
    }
};