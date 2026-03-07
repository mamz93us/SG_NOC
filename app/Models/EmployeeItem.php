<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeItem extends Model
{
    protected $fillable = [
        'employee_id', 'item_name', 'item_type', 'serial_number',
        'model', 'condition', 'assigned_date', 'returned_date', 'notes',
    ];

    protected $casts = [
        'assigned_date' => 'date',
        'returned_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function isActive(): bool
    {
        return is_null($this->returned_date);
    }

    public function conditionBadgeClass(): string
    {
        return match ($this->condition) {
            'good'  => 'success',
            'fair'  => 'warning',
            'poor'  => 'danger',
            default => 'secondary',
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->item_type) {
            'laptop', 'desktop'  => 'primary',
            'phone', 'tablet'    => 'info',
            'headset', 'mouse', 'keyboard' => 'secondary',
            default              => 'dark',
        };
    }

    public function typeIcon(): string
    {
        return match ($this->item_type) {
            'laptop'   => 'bi-laptop',
            'desktop'  => 'bi-pc-display',
            'phone'    => 'bi-phone',
            'tablet'   => 'bi-tablet',
            'headset'  => 'bi-headset',
            'keyboard' => 'bi-keyboard',
            'mouse'    => 'bi-mouse',
            default    => 'bi-box',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->item_type) {
            'laptop'   => 'Laptop',
            'desktop'  => 'Desktop',
            'phone'    => 'Phone',
            'tablet'   => 'Tablet',
            'headset'  => 'Headset',
            'keyboard' => 'Keyboard',
            'mouse'    => 'Mouse',
            default    => 'Other',
        };
    }
}
