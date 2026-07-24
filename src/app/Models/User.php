<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
        'onboarding_completed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /**
     * Whether the user has a confirmed second factor. The backing columns
     * (`two_factor_*`) are already reserved on this model; this stays false
     * until the 2FA enrolment flow + migration land, at which point the login
     * gate in {@see \App\Services\Auth\Authenticator} starts enforcing it.
     */
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Public URL for the user's avatar, or null when none is set.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
        );
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(BankAccount::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function incomeEntries()
    {
        return $this->hasMany(IncomeEntry::class);
    }

    public function fixedExpenses()
    {
        return $this->hasMany(FixedExpense::class);
    }

    public function envelopePools()
    {
        return $this->hasMany(EnvelopePool::class);
    }

    public function savingsMilestones()
    {
        return $this->hasMany(SavingsMilestone::class);
    }

    public function yearlySnapshots()
    {
        return $this->hasMany(YearlySnapshot::class);
    }
}
