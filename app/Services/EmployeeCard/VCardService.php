<?php

namespace App\Services\EmployeeCard;

use App\Models\Employee;
use App\Models\Setting;

class VCardService
{
    public function generate(Employee $employee): string
    {
        $identity = $employee->identityUser;
        $branch   = $employee->branch;
        $setting  = Setting::get();

        $name     = $employee->name;
        $email    = $employee->email ?: $identity?->mail ?: $identity?->user_principal_name;
        $title    = $employee->job_title ?: $identity?->job_title;
        $dept     = $identity?->department ?: $employee->department?->name;
        $phone    = $identity?->phone_number;
        $mobile   = $employee->mobile_phone ?: $identity?->mobile_phone;
        $ext      = $employee->extension_number;
        $org      = $setting->company_name ?: 'Samir Group';
        $city     = $branch?->city ?: $identity?->city;
        $office   = $branch?->name;

        // Build full name parts
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            "FN:{$this->esc($name)}",
            "N:{$this->esc($lastName)};{$this->esc($firstName)};;;",
        ];

        if ($title) {
            $lines[] = "TITLE:{$this->esc($title)}";
        }
        if ($dept || $org) {
            $lines[] = "ORG:{$this->esc($org)};{$this->esc($dept ?? '')}";
        }
        if ($email) {
            $lines[] = "EMAIL;TYPE=WORK,INTERNET:{$this->esc($email)}";
        }
        if ($phone) {
            $lines[] = "TEL;TYPE=WORK,VOICE:{$this->esc($phone)}";
        }
        if ($mobile) {
            $lines[] = "TEL;TYPE=CELL,VOICE:{$this->esc($mobile)}";
        }
        if ($ext) {
            $lines[] = "TEL;TYPE=WORK,X-extension:{$this->esc($ext)}";
        }
        if ($city || $office) {
            $lines[] = "ADR;TYPE=WORK:;;{$this->esc($office ?? '')};{$this->esc($city ?? '')};;;";
        }
        // Card URL in NOTE so contacts can find the digital card
        if ($employee->card_token) {
            $cardUrl = url("/card/{$employee->card_token}");
            $lines[] = "URL;TYPE=pref:{$cardUrl}";
            $lines[] = "NOTE:Digital card: {$cardUrl}";
        }

        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function esc(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return str_replace([',', ';', '\\', "\n"], ['\\,', '\\;', '\\\\', '\\n'], $value);
    }
}
