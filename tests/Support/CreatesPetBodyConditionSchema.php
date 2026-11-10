<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesPetBodyConditionSchema
{
    private function createPetBodyConditionSchema(): void
    {
        if (! Schema::hasTable('clinic_appointments')) {
            Schema::create('clinic_appointments', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('pet_id');
                $table->string('status');
                $table->date('appointment_date');
            });
        } elseif (! Schema::hasColumn('clinic_appointments', 'appointment_date')) {
            Schema::table('clinic_appointments', fn (Blueprint $table) => $table->date('appointment_date')->nullable());
        }

        if (! Schema::hasTable('clinic_records')) {
            Schema::create('clinic_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('clinic_appointment_id')->unique();
            });
        }

        if (! Schema::hasTable('clinic_vitals')) {
            Schema::create('clinic_vitals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('clinic_appointment_id')->unique();
                $table->unsignedTinyInteger('body_condition_score')->nullable();
            });
        }
    }
}
