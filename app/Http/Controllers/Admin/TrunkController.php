<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UcmServer;
use App\Services\IppbxApiService;
use Illuminate\Http\Request;

class TrunkController extends Controller
{
    /**
     * Show VoIP trunks page with dropdown for UCM selection
     */
    public function index(Request $request)
    {
        $ucmServers  = UcmServer::active()->orderBy('name')->get();
        $trunks      = [];
        $error       = null;
        $selectedUcm = null;

        $ucmId = $request->get('ucm_id');

        if ($ucmId) {
            $selectedUcm = UcmServer::find($ucmId);

            if ($selectedUcm) {
                try {
                    $api    = new IppbxApiService($selectedUcm);
                    $trunks = $api->listVoIPTrunks();
                } catch (\Exception $e) {
                    $error = 'Could not connect to UCM: ' . $e->getMessage();
                }
            }
        } elseif ($ucmServers->count() === 1) {
            // Auto-select if only one UCM
            $selectedUcm = $ucmServers->first();
            try {
                $api    = new IppbxApiService($selectedUcm);
                $trunks = $api->listVoIPTrunks();
            } catch (\Exception $e) {
                $error = 'Could not connect to UCM: ' . $e->getMessage();
            }
        }

        return view('admin.trunks.index', compact(
            'ucmServers',
            'trunks',
            'selectedUcm',
            'error'
        ));
    }
}
