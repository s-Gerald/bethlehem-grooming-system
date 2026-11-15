<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_group_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('clinic_consent');
            $table->string('signer_name', 200);
            $table->longText('signature_image');
            $table->json('signature_strokes');
            $table->timestamp('signed_at');
            $table->string('consent_version', 40);
            $table->json('agreement_snapshot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_consents');
    }
};
