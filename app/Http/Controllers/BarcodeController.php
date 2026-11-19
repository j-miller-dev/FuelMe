<?php

namespace App\Http\Controllers;

use App\Domain\Foods\BarcodeLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BarcodeController extends Controller
{
    /** GET /foods/barcode?code=9300650018860 -> { status: found|invalid|not_found|incomplete|offline, food?, prefill? } */
    public function show(Request $request, BarcodeLookup $lookup): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        return response()->json($lookup->find($data['code'], $request->user()->id));
    }
}
