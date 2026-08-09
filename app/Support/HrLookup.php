<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;

/**
 * Identifier resolution for the HR API.
 *
 * The HR system does not know NOC's primary keys, so every endpoint accepts the
 * identifiers HR actually holds — an Oracle employee number, a work email, a
 * branch or department name — and resolves them here. The portal forms don't
 * need this because their pickers post ids directly.
 *
 * Every resolver returns null rather than throwing, so the caller decides
 * whether a miss is fatal for that field.
 */
class HrLookup
{
    /**
     * Find an employee by any identifier the HR system might send.
     * Precedence is most-specific first: NOC id, Oracle number, work email, name.
     */
    public static function employee(
        int|string|null $id = null,
        ?string $oracleEmpNo = null,
        ?string $email = null,
        ?string $name = null,
    ): ?Employee {
        if ($id !== null && $id !== '') {
            if ($found = Employee::find($id)) {
                return $found;
            }
        }

        if ($oracleEmpNo !== null && trim($oracleEmpNo) !== '') {
            // Oracle numbers collide across the SSS Egypt and SamirGroup books,
            // so prefer an active match before falling back to any match.
            $query = Employee::where('oracle_emp_no', trim($oracleEmpNo));
            if ($found = (clone $query)->where('status', 'active')->first() ?? $query->first()) {
                return $found;
            }
        }

        if ($email !== null && trim($email) !== '') {
            if ($found = Employee::where('email', trim($email))->first()) {
                return $found;
            }
        }

        if ($name !== null && trim($name) !== '') {
            $matches = Employee::where('name', trim($name))->limit(2)->get();
            // An exact-name match is only trustworthy when it is unique.
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /** Branch by id or exact name (case-insensitive). */
    public static function branch(int|string|null $id = null, ?string $name = null): ?Branch
    {
        if ($id !== null && $id !== '') {
            if ($found = Branch::find($id)) {
                return $found;
            }
        }

        if ($name !== null && trim($name) !== '') {
            return Branch::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();
        }

        return null;
    }

    /** Department by id or exact name (case-insensitive). */
    public static function department(int|string|null $id = null, ?string $name = null): ?Department
    {
        if ($id !== null && $id !== '') {
            if ($found = Department::find($id)) {
                return $found;
            }
        }

        if ($name !== null && trim($name) !== '') {
            return Department::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();
        }

        return null;
    }
}
