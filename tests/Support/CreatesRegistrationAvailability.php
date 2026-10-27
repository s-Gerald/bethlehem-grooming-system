<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesRegistrationAvailability
{
    private function createRegistrationAvailability(): void
    {
        if (! Schema::hasTable('pet_groomers')) {
            Schema::create('pet_groomers', function (Blueprint $table) {
                $table->id();
                $table->string('first_name');
                $table->boolean('on_duty')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('clinic_settings')) {
            Schema::create('clinic_settings', function (Blueprint $table) {
                $table->id();
                foreach (['clinic', 'grooming'] as $service) {
                    $table->time($service.'_open_time')->default('08:00:00');
                    $table->time($service.'_close_time')->default('17:00:00');
                }
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('clinic_closures')) {
            Schema::create('clinic_closures', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->string('service')->nullable();
                $table->date('start_date');
                $table->date('end_date');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }
}
