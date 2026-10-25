<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Historical identifiers deliberately survive account/record removal.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_display');
            $table->string('actor_role')->nullable();
            $table->string('actor_identity')->nullable();
            $table->string('area', 16);
            $table->string('action', 64);
            $table->string('subject_type', 48);
            $table->string('subject_id', 64);
            $table->string('context');
            $table->unsignedBigInteger('pet_id')->nullable();
            $table->json('changes')->nullable();
            $table->boolean('operational')->default(false);
            $table->string('result', 16)->default('success');
            $table->string('event_key', 64)->nullable()->unique();
            $table->dateTime('occurred_at', 6);
            $table->index(['occurred_at', 'id']);
            $table->index(['area', 'occurred_at', 'id']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['operational', 'area', 'occurred_at', 'id'], 'activity_feed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
