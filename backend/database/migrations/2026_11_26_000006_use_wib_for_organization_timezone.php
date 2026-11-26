<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceTimezone('Asia/Bangkok', 'Asia/Jakarta');
    }

    public function down(): void
    {
        $this->replaceTimezone('Asia/Jakarta', 'Asia/Bangkok');
    }

    private function replaceTimezone(string $from, string $to): void
    {
        DB::table('system_settings')->where('setting_key', 'organization_timezone')
            ->where('setting_value', json_encode($from, JSON_THROW_ON_ERROR))
            ->update(['setting_value' => json_encode($to, JSON_THROW_ON_ERROR)]);
    }
};
