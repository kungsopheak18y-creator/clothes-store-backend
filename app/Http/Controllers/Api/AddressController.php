<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    // GET /addresses - get my addresses
    public function index(Request $request)
    {
        $addresses = Address::where('user_id', $request->user()->id)
            ->orderBy('is_default', 'desc')
            ->get();

        return response()->json([
            'message'   => 'Addresses retrieved successfully',
            'addresses' => $addresses,
        ]);
    }

    // POST /addresses - create address
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'   => 'required|string|max:255',
            'last_name'    => 'required|string|max:255',
            'phone'        => 'required|string|max:20',
            'address_line' => 'required|string|max:500',
            'city'         => 'required|string|max:255',
            'country'      => 'nullable|string|max:255',
            'is_default'   => 'nullable|boolean',
        ]);

        $validated['user_id'] = $request->user()->id;

        // If this is set as default unset others
        if (!empty($validated['is_default'])) {
            Address::where('user_id', $request->user()->id)
                ->update(['is_default' => false]);
        }

        $address = Address::create($validated);

        return response()->json([
            'message' => 'Address created successfully',
            'address' => $address,
        ], 201);
    }

    // PUT /addresses/{id} - update address
    public function update(Request $request, Address $address)
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'first_name'   => 'sometimes|string|max:255',
            'last_name'    => 'sometimes|string|max:255',
            'phone'        => 'sometimes|string|max:20',
            'address_line' => 'sometimes|string|max:500',
            'city'         => 'sometimes|string|max:255',
            'country'      => 'nullable|string|max:255',
            'is_default'   => 'nullable|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            Address::where('user_id', $request->user()->id)
                ->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json([
            'message' => 'Address updated successfully',
            'address' => $address,
        ]);
    }

    // DELETE /addresses/{id} - delete address
    public function destroy(Request $request, Address $address)
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $address->delete();

        return response()->json([
            'message' => 'Address deleted successfully',
        ]);
    }

    // PATCH /addresses/{id}/default - set as default
    public function setDefault(Request $request, Address $address)
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Unset all others first
        Address::where('user_id', $request->user()->id)
            ->update(['is_default' => false]);

        // Set this one as default
        $address->update(['is_default' => true]);

        return response()->json([
            'message' => 'Default address updated successfully',
            'address' => $address->fresh(),
        ]);
    }
}