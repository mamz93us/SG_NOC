<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\PhoneRequestLog;
use App\Models\Setting;
use Illuminate\Http\Request;

class PhonebookController extends Controller
{
    /**
     * Display public contacts page
     */
    public function index(Request $request)
    {
        $query = Contact::with('branch')->orderBy('first_name');

        // Search functionality
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $contacts = $query->paginate(12);
        $settings = Setting::get();

        return view('contacts.index', compact('contacts', 'settings'));
    }

    /**
     * Full print layout
     */
    public function print(Request $request)
    {
        $query = Contact::with('branch')->orderBy('first_name');

        // Filter by branch if specified
        if ($request->has('branch_id') && $request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        $contacts = $query->get();
        $branches = Branch::orderBy('name')->get();
        $selectedBranch = $request->branch_id ? Branch::find($request->branch_id) : null;
        $settings = Setting::first();

        return view('contacts.print', compact('contacts', 'branches', 'selectedBranch', 'settings'));
    }

    /**
     * Compact print layout (5 columns, landscape)
     */
    public function printCompact(Request $request)
    {
        $query = Contact::with('branch')->orderBy('first_name');

        // Filter by branch if specified
        if ($request->has('branch_id') && $request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        $contacts = $query->get();
        $branches = Branch::orderBy('name')->get();
        $selectedBranch = $request->branch_id ? Branch::find($request->branch_id) : null;
        $settings = Setting::first();

        return view('contacts.print-compact', compact('contacts', 'branches', 'selectedBranch', 'settings'));
    }

    /**
     * Generate phonebook.xml for Grandstream UCM
     */
    public function generate(Request $request)
    {
        // Log requesting phone (IP, User-Agent, MAC, model)
        $ip        = $request->ip();
        $userAgent = $request->header('User-Agent', '');

        $mac   = null;
        $model = null;

        // UA examples:
        //   "Grandstream Model HW GRP2616 SW 1.0.13.59 DevId ec74d7800474"   (no colons)
        //   "Grandstream Model HW GRP2601W SW 1.0.7.32 DevId EC:74:D7:89:1A:76" (with colons)
        if (preg_match('/Model HW\s+([A-Z0-9\-]+)/i', $userAgent, $m)) {
            $model = strtoupper($m[1]); // GRP2616 / GRP2601W
        }

        // Allow both formats: plain hex (ec74d7800474) or colon-separated (EC:74:D7:89:1A:76)
        if (preg_match('/DevId\s+([0-9a-fA-F:]+)/i', $userAgent, $m)) {
            // Normalize: strip colons → lowercase hex, e.g. ec74d789001a76 → ec74d789001a76
            $mac = strtolower(str_replace(':', '', $m[1]));
        }

        try {
            PhoneRequestLog::create([
                'ip'         => $ip ?? '0.0.0.0',
                'user_agent' => $userAgent,
                'mac'        => $mac,
                'model'      => $model,
            ]);
        } catch (\Throwable) {
            // Don't let logging break the phonebook XML response
        }

        // Build XML phonebook
        $xmlString = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xmlString .= "<AddressBook>\n";
        $xmlString .= "    <version>1</version>\n";

        // Add a default "Global" group for contacts without a specific branch
        $xmlString .= "    <pbgroup>\n";
        $xmlString .= "        <id>0</id>\n";
        $xmlString .= "        <name>Global</name>\n";
        $xmlString .= "        <photos></photos>\n";
        $xmlString .= "        <ringtones></ringtones>\n";
        $xmlString .= "        <RingtoneIndex>0</RingtoneIndex>\n";
        $xmlString .= "    </pbgroup>\n";

        foreach (Branch::orderBy('id')->get() as $branch) {
            $xmlString .= "    <pbgroup>\n";
            $xmlString .= "        <id>{$branch->id}</id>\n";
            $xmlString .= "        <name>{$branch->name}</name>\n";
            $xmlString .= "        <photos></photos>\n";
            $xmlString .= "        <ringtones></ringtones>\n";
            $xmlString .= "        <RingtoneIndex>0</RingtoneIndex>\n";
            $xmlString .= "    </pbgroup>\n";
        }

        foreach (Contact::orderBy('first_name')->get() as $c) {
            $fname = htmlspecialchars($c->first_name, ENT_XML1);
            $lname = htmlspecialchars($c->last_name ?? '', ENT_XML1);
            $email = htmlspecialchars($c->email ?? '', ENT_XML1);
            $groupId = $c->branch_id ?? 0;

            $xmlString .= "    <Contact>\n";
            $xmlString .= "        <id>{$c->id}</id>\n";
            $xmlString .= "        <FirstName>{$fname}</FirstName>\n";
            $xmlString .= "        <LastName>{$lname}</LastName>\n";
            $xmlString .= "        <Department></Department>\n";
            $xmlString .= "        <Primary>0</Primary>\n";
            $xmlString .= "        <Frequent>0</Frequent>\n";
            $xmlString .= "        <Phone type=\"Work\">\n";
            $xmlString .= "            <phonenumber>{$c->phone}</phonenumber>\n";
            $xmlString .= "            <accountindex>1</accountindex>\n";
            $xmlString .= "        </Phone>\n";
            if ($email) {
                $xmlString .= "        <Mail type=\"Work\">{$email}</Mail>\n";
            }
            $xmlString .= "        <Group>{$groupId}</Group>\n";
            $xmlString .= "        <PhotoUrl></PhotoUrl>\n";
            $xmlString .= "        <RingtoneUrl></RingtoneUrl>\n";
            $xmlString .= "        <RingtoneIndex>0</RingtoneIndex>\n";
            $xmlString .= "    </Contact>\n";
        }

        $xmlString .= "</AddressBook>";
        return response($xmlString, 200)
            ->header('Content-Type', 'text/xml; charset=utf-8');
    }

    /**
     * XML Preview Page
     */
    public function preview()
    {
        $branches = Branch::orderBy('id')->get();
        $contacts = Contact::orderBy('first_name')->get();

        return view('admin.xml-preview', compact('branches', 'contacts'));
    }
}
