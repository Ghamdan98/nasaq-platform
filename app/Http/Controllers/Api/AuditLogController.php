<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditLogController extends Controller
{
    public function index()
    {
        abort_unless(request()->user()->isAdmin(), 403);
        return response()->json(AuditLog::with('user')->latest()->get());
    }
}
