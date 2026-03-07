<?php

namespace App\Exports;

use App\Models\Contact;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ContactsExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $contacts;

    public function __construct($contacts)
    {
        $this->contacts = $contacts;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->contacts;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'ID',
            'First Name',
            'Last Name',
            'Phone',
            'Email',
            'Branch ID',
            'Branch Name',
            'Created At',
        ];
    }

    /**
     * @param Contact $contact
     * @return array
     */
    public function map($contact): array
    {
        return [
            $contact->id,
            $contact->first_name,
            $contact->last_name,
            $contact->phone,
            $contact->email,
            $contact->branch_id,
            $contact->branch->name ?? '',
            $contact->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
            1 => ['font' => ['bold' => true]],
        ];
    }
}
