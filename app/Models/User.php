<?php

namespace App\Models;

use App\Support\StaffRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    private const PASSWORD_SETUP_REQUIRED_PREFIX = '!staff-password-setup-required!';

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password_hash',
        'role',
        'staff_type',
        'staff_subrole',
        'customer_tier',
        'is_active',
        'is_archived',
        'archived_at',
        'account_deleted_at',
        'email_verified_at',
        'email_verification_token',
        'email_verification_expires_at',
        'email_verification_code_hash',
        'email_verification_attempts',
        'email_verification_last_sent_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password_hash',
        'email_verification_token',
        'email_verification_code_hash',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'email_verification_last_sent_at' => 'datetime',
        'email_verification_attempts' => 'integer',
        'email_verification_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'account_deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->role === 'staff'
                && $user->isDirty(['role', 'staff_type', 'staff_subrole'])
                && ($user->staff_type !== null || $user->staff_subrole !== null
                    || $user->getOriginal('staff_type') !== null || $user->getOriginal('staff_subrole') !== null)) {
                StaffRole::assertValid((string) $user->staff_type, $user->staff_subrole);
            }
        });
    }

    // Tell Sanctum to use password_hash instead of password
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public static function passwordSetupPlaceholder(): string
    {
        return self::PASSWORD_SETUP_REQUIRED_PREFIX.Str::random(40);
    }

    public function requiresPasswordSetup(): bool
    {
        return $this->role === 'staff'
            && str_starts_with(
                (string) $this->password_hash,
                self::PASSWORD_SETUP_REQUIRED_PREFIX,
            );
    }

    public function clinicPermissions(): array
    {
        $admin = $this->role === 'admin';
        $clinicStaff = $this->role === 'staff' && $this->staff_type === 'clinic';
        $vet = $clinicStaff && $this->staff_subrole === 'veterinarian';
        $receptionist = $clinicStaff && $this->staff_subrole === 'clinic_receptionist';

        return [
            'access' => $admin || $vet || $receptionist,
            'intake' => $admin || $vet || $receptionist,
            'clinical' => $admin || $vet,
            'billing_view' => $admin || $vet || $receptionist,
            'billing_edit' => $admin || $vet,
            'payment' => $admin || $vet || $receptionist,
            'customers' => $admin || $vet || $receptionist,
            'queue' => $admin || $vet || $receptionist,
            'services_view' => $admin || $vet || $receptionist,
            'services_manage' => $admin,
        ];
    }

    public function sidebarRevision(): string
    {
        // Uses already-loaded attributes; no queries or token secrets.
        return hash('sha256', json_encode([
            $this->user_id, $this->role, $this->staff_type, $this->staff_subrole,
            $this->first_name, $this->last_name, $this->clinicPermissions(),
        ], JSON_THROW_ON_ERROR));
    }

    public function isVeterinarian(): bool
    {
        return $this->role === 'staff' && $this->staff_type === 'clinic' && $this->staff_subrole === 'veterinarian';
    }

    public function scopeActiveVeterinarian(Builder $query): Builder
    {
        return $query->where('role', 'staff')->where('staff_type', 'clinic')->where('staff_subrole', 'veterinarian')->where('is_active', true);
    }

    public function canAccessGrooming(): bool
    {
        return $this->role === 'admin'
            || ($this->role === 'staff' && $this->staff_type === 'grooming'
                && $this->staff_subrole === 'grooming_receptionist');
    }

    public function passwordResetRequest()
    {
        return $this->hasOne(PasswordResetRequest::class, 'user_id', 'user_id');
    }

    /**
     * Store account names in a consistent display format.
     */
    public static function normalizeName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name)) ?: trim((string) $name);

        return mb_convert_case(mb_strtolower($normalized), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Customer accounts are operational only after email ownership is proven.
     * The schema check keeps focused tests with reduced user tables compatible.
     */
    public function scopeRegisteredCustomer(Builder $query): Builder
    {
        $query->where('role', 'customer');

        if (Schema::hasColumn($this->getTable(), 'email_verified_at')) {
            $query->whereNotNull('email_verified_at');
        }

        if (Schema::hasColumn($this->getTable(), 'account_deleted_at')) {
            $query->whereNull('account_deleted_at');
        }

        return $query;
    }

    public function pets()
    {
        return $this->hasMany(Pet::class, 'user_id', 'user_id');
    }

    public function vaccinationsRecorded()
    {
        return $this->hasMany(VaccinationRecord::class, 'recorded_by_user_id', 'user_id');
    }

    public function vaccinationsPublished()
    {
        return $this->hasMany(VaccinationRecord::class, 'published_by_user_id', 'user_id');
    }

    public function vaccinationsVoided()
    {
        return $this->hasMany(VaccinationRecord::class, 'voided_by_user_id', 'user_id');
    }
}
