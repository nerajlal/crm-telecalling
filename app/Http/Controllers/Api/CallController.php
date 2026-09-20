<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CallController extends Controller
{
    /**
     * Receive and sync call logs from the Android App
     */
    public function sync(Request $request)
    {
        $attempts = $request->input('attempts', []);
        $recentCallLog = $request->input('recentCallLog');

        // Here we would match attempts with the recentCallLog duration
        // and update our database records.
        
        // For now, log the received payload
        \Log::info('Received call sync data', [
            'attempts' => $attempts,
            'recentCallLog' => $recentCallLog
        ]);

        return response()->json(['status' => 'success', 'message' => 'Synced']);
    }
}
