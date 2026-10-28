<?php

namespace App\Http\Controllers;

use App\Models\ClinicSetting;
use App\Models\PetGroomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PetGroomerController extends Controller
{
    public function index()
    {
        return response()->json(['pet_groomers' => PetGroomer::where('on_duty', true)
            ->orderBy('first_name')->orderBy('id')->get(['id', 'first_name'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['first_name' => \App\Support\HumanName::rules()]);
        return $this->save($data);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'first_name' => ['sometimes', ...\App\Support\HumanName::rules()],
            'on_duty' => ['sometimes', 'required', 'boolean'],
        ]);
        return $this->save($data, $id);
    }

    private function save(array $data, ?int $id = null)
    {
        return DB::transaction(function () use ($data, $id) {
            ClinicSetting::current(lockForUpdate: true);
            $groomer = $id === null ? new PetGroomer(['on_duty' => false]) : PetGroomer::findOrFail($id);
            if (isset($data['first_name'])) {
                $data['first_name'] = $data['first_name'] === $groomer->first_name ? $data['first_name'] : \App\Support\HumanName::normalize($data['first_name']);
            }
            if (($data['on_duty'] ?? false) && ! $groomer->on_duty
                && PetGroomer::where('on_duty', true)->count() >= ClinicSetting::MAX_GROOMERS_ON_DUTY) {
                throw ValidationException::withMessages(['on_duty' => 'Up to 5 Pet Groomers can be on duty.']);
            }
            $groomer->fill($data)->save();
            return response()->json([
                'pet_groomers' => PetGroomer::orderBy('id')->get(),
                'groomers_on_duty' => PetGroomer::where('on_duty', true)->count(),
            ]);
        });
    }
}
