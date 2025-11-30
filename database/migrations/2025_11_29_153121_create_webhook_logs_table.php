<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->json('payload');
            $table->enum('status', ['processed', 'duplicate'])->default('processed');
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index('idempotency_key');
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
