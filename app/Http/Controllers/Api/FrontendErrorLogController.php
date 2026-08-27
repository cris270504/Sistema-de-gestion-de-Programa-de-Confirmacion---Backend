<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FrontendErrorLog;
use Illuminate\Http\Request;

class FrontendErrorLogController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'stack' => 'nullable|string|max:8000',
            'url' => 'nullable|string|max:2048',
        ]);

        FrontendErrorLog::create([
            'user_id' => $request->user()->id,
            'message' => $data['message'],
            'stack' => $data['stack'] ?? null,
            'url' => $data['url'] ?? null,
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        // 204: el frontend hace este POST "fire and forget", no necesita cuerpo de respuesta.
        return response()->noContent();
    }
}
