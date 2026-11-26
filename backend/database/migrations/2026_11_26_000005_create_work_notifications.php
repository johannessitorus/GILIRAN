<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('department_id')->constrained();
            $table->string('event', 40);
            $table->unsignedBigInteger('subject_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('read_at')->nullable();
            $table->index(['user_id', 'department_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_notifications');
    }
};
