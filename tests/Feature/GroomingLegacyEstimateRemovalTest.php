<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroomingLegacyEstimateRemovalTest extends TestCase
{
    public function test_retirement_drops_only_legacy_times_and_preserves_every_current_configuration(): void
    {
        Schema::create('services',function(Blueprint $t) {
            $t->increments('service_id'); $t->string('slug'); $t->decimal('price_small',8,2); $t->boolean('is_active');
            $t->json('time_estimates')->nullable(); $t->json('haircut_options')->nullable(); $t->json('legacy_complete_estimates')->nullable();
        });
        Schema::create('booking_pets',function(Blueprint $t) {
            $t->increments('booking_pet_id'); $t->string('grooming_preference'); $t->string('grooming_preference_name');
            $t->dateTime('grooming_start_time'); $t->dateTime('grooming_end_time');
        });
        foreach (['regular_dog_grooming','deluxe_dog_grooming','cat_full_grooming','partial_grooming','nail_clipping','custom_package'] as $i=>$slug) {
            DB::table('services')->insert(['slug'=>$slug,'price_small'=>500+$i,'is_active'=>$i%2===0,
                'time_estimates'=>$i===0 ? null : json_encode(['small'=>[15+$i,30+$i]]),
                'haircut_options'=>json_encode([['id'=>'kept_'.$i,'name'=>'Saved style','isActive'=>false,'estimates'=>['small'=>[20,25]]]]),
                'legacy_complete_estimates'=>json_encode(['kept_'.$i=>['small'=>[999,999]]])]);
        }
        DB::table('booking_pets')->insert(['grooming_preference'=>'kept_0','grooming_preference_name'=>'Original style',
            'grooming_start_time'=>'2026-11-04 13:00:00','grooming_end_time'=>'2026-11-04 14:00:00']);
        $services=DB::table('services')->get()->map(fn($s)=>collect((array)$s)->except('legacy_complete_estimates')->all())->all();
        $pets=DB::table('booking_pets')->get()->all();
        $migration=database_path('migrations/2026_11_05_000002_retire_legacy_grooming_estimates.php');
        $this->assertFileExists($migration);
        (require $migration)->up();
        $this->assertFalse(Schema::hasColumn('services','legacy_complete_estimates'));
        $this->assertSame($services,DB::table('services')->get()->map(fn($s)=>(array)$s)->all());
        $this->assertEquals($pets,DB::table('booking_pets')->get()->all());
        Schema::drop('booking_pets'); Schema::drop('services');
    }
}
