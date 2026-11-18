<?php

namespace Tests\Unit;

use App\Models\PosTransaction;
use App\Models\User;
use Tests\TestCase;

class ActorAttributionTest extends TestCase
{
    public function test_compact_actor_and_cashier_labels_use_first_name_and_operational_role(): void
    {
        foreach ([
            ['Gerald', 'admin', 'owner', null, 'Gerald · Admin'],
            ['Maria', 'admin', 'manager', null, 'Maria · Manager'],
            ['John', 'staff', null, 'veterinarian', 'John · Veterinarian'],
            ['Ana', 'staff', null, 'clinic_receptionist', 'Ana · Clinic Receptionist'],
            ['Mark', 'staff', null, 'grooming_receptionist', 'Mark · Grooming Receptionist'],
            ['Jamie', 'staff', null, null, 'Jamie · Staff'],
        ] as [$first, $role, $adminType, $subrole, $expected]) {
            $actor = new User(['first_name' => $first, 'last_name' => 'Marino', 'role' => $role,
                'admin_type' => $adminType, 'staff_subrole' => $subrole]);
            $this->assertSame($expected, $actor->actorAttribution());
            $receipt = (new PosTransaction(['reference' => 'POS-000001', 'payment_method' => 'cash']))->setRelation('cashier', $actor)
                ->setRelation('gatewayPayment', null)->setRelation('items', collect())->receiptSnapshot();
            $this->assertSame($expected, $receipt['cashier_name']);
        }
        $this->assertNull((new User(['first_name' => '  ', 'role' => 'admin']))->actorAttribution());
    }
}
