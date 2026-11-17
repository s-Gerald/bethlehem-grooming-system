<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->string('primary_contact_number', 50)->default('7007-3122');
            $table->string('secondary_contact_number', 50)->default('0917 113 1941');
            $table->string('clinic_address', 500)->default('K20 Ortigas Avenue Extension, Pearl Ave. St., Ortigas, Greenheights Subd., Brgy. San Isidro, Taytay, Rizal, Taytay, Philippines, 1920');
            $table->string('map_directions_url', 2048)->default('https://www.bing.com/maps/search?v=2&pc=FACEBK&mid=8100&name=K20+Ortigas+Avenue+Extension%2C+Pearl+Ave.+St.%2C+Ortigas%2C+Greenheights+Subd.%2C+Brgy.+San+Isidro%2C+++Taytay%2C+Rizal%2C+Taytay%2C+Philippines%2C+1920&mkt=en-US&FORM=FBKPL1&q=14.57937%2C+121.13774&cp=14.579154%7E121.137903&lvl=18.8&style=r');
            $table->string('facebook_url', 2048)->default('https://www.facebook.com/2022BethlehemAC');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->dropColumn(['primary_contact_number', 'secondary_contact_number', 'clinic_address', 'map_directions_url', 'facebook_url']);
        });
    }
};
