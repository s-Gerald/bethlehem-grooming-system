<?php

use App\Support\PetBodyCondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pets', 'body_condition')) {
            Schema::table('pets', fn (Blueprint $table) => $table->enum('body_condition', PetBodyCondition::VALUES)->nullable());
        }

        $score = PetBodyCondition::latestAssessment()->select('vitals.body_condition_score')
            ->whereColumn('visits.pet_id', 'pets.pet_id')->limit(1);
        DB::table('pets')->select('pet_id')->whereNull('body_condition')->chunkById(500, function ($batch) use ($score) {
            DB::transaction(function () use ($batch, $score) {
                $pets = DB::table('pets')->whereIn('pet_id', $batch->pluck('pet_id'))->whereNull('body_condition')
                    ->select('pet_id', 'clinic_verified_fields')->selectSub($score, 'score')->lockForUpdate()->get();
                foreach ($pets as $pet) {
                    $condition = PetBodyCondition::fromScore($pet->score);
                    if (! $condition) continue;
                    $verified = (array) json_decode($pet->clinic_verified_fields ?? '[]', true);
                    DB::table('pets')->where('pet_id', $pet->pet_id)->update([
                        'body_condition' => $condition,
                        'clinic_verified_fields' => json_encode(array_values(array_unique([...$verified, 'body_condition'])), JSON_THROW_ON_ERROR),
                    ]);
                }
            });
        }, 'pet_id');
    }

    public function down(): void
    {
        DB::table('pets')->whereNotNull('clinic_verified_fields')->select('pet_id', 'clinic_verified_fields')
            ->chunkById(500, function ($pets) {
                foreach ($pets as $pet) {
                    $fields = (array) json_decode($pet->clinic_verified_fields, true);
                    if (! in_array('body_condition', $fields, true)) continue;
                    DB::table('pets')->where('pet_id', $pet->pet_id)->update([
                        'clinic_verified_fields' => json_encode(array_values(array_diff($fields, ['body_condition'])), JSON_THROW_ON_ERROR),
                    ]);
                }
            }, 'pet_id');
        Schema::table('pets', fn (Blueprint $table) => $table->dropColumn('body_condition'));
    }
};
