<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'confirm_password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role'     => 'user',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Register successful',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    public function login(Request $request)
        {
            $validated = $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user || !Hash::check($validated['password'], $user->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'user'    => $user,
                'token'   => $token,
            ]);
        }

        public function logout(Request $request)
        {
            $request->user()->currentAccessToken()->delete();

            return response()->json(['message' => 'Logged out successfully']);
        }

        public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
        {
            $user = $request->user();

            $request->validate([
                'first_name'    => 'nullable|string|max:100',
                'last_name'     => 'nullable|string|max:100',
                'phone'         => 'nullable|string|max:20|unique:users,phone,' . $user->id,
                'gender'        => 'nullable|string|max:20',
                'date_of_birth' => 'nullable|date',
            ]);

            $firstName = $request->filled('first_name') ? $request->first_name : $user->first_name;
            $lastName  = $request->filled('last_name')  ? $request->last_name  : $user->last_name;

            $user->update([
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'name'          => ($firstName && $lastName) ? "$firstName $lastName" : $user->name,
                'phone'         => $request->filled('phone')         ? $request->phone         : $user->phone,
                'gender'        => $request->filled('gender')        ? $request->gender        : $user->gender,
                'date_of_birth' => $request->filled('date_of_birth') ? $request->date_of_birth : $user->date_of_birth,
            ]);

            return response()->json([
                'message' => 'Profile updated successfully',
                'user'    => $user->fresh(),
            ]);
        }

    public function changePassword(Request $request)
        {
            $request->validate([
                'current_password' => 'required|string',
                'new_password'     => 'required|string|min:6',
            ]);

            $user = $request->user();

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json(['error' => 'Current password is incorrect'], 401);
            }

            $user->update(['password' => bcrypt($request->new_password)]);

            return response()->json(['message' => 'Password updated successfully']);
        }

    public function googleRedirect()
        {
            return Socialite::driver('google')
                ->stateless()
                ->redirect();
        }

    public function googleCallback()
        {
            $googleUser = Socialite::driver('google')->stateless()->user();

            $user = User::updateOrCreate(
                ['email' => $googleUser->getEmail()],
                [
                    'first_name' => $googleUser->user['given_name']  ?? '',
                    'last_name'  => $googleUser->user['family_name'] ?? '',
                    'name'       => $googleUser->getName(),
                    'google_id'  => $googleUser->getId(),
                    'password'   => bcrypt(str()->random(24)),
                    'role'       => 'user',
                ]
            );

            $token = $user->createToken('auth_token')->plainTextToken;

            return redirect(env('FRONTEND_URL') . '/auth/google/callback?token=' . $token);
        }
}