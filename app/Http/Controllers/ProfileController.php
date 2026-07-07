<?php

namespace App\Http\Controllers;

use App\Models\Otp;
use App\Notifications\SendOtpNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($request->user());
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'current_password' => 'required_with:email|string',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->name = $request->name;
        $user->phone = $request->phone;
        $user->address = $request->address;

        $emailChanged = false;

        if ($request->has('email') && $request->email !== $user->getOriginal('email')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json(['message' => 'Current password is incorrect'], 400);
            }

            $user->email = $request->email;
            $user->email_verified_at = null;
            $emailChanged = true;
        }

        if ($request->hasFile('avatar')) {
            try {
                if ($user->avatar && str_starts_with($user->avatar, 'https://res.cloudinary.com/')) {
                    cloudinary()->uploadApi()->destroy($this->extractCloudinaryPublicId($user->avatar));
                }

                $uploadedFile = cloudinary()->uploadApi()->upload(
                    $request->file('avatar')->getRealPath(),
                    ['folder' => 'avatars']
                );
                $user->avatar = $uploadedFile['secure_url'];
            } catch (\Exception $e) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ], 500);
            }
        }

        $user->save();

        if ($emailChanged) {
            try {
                app(\App\Http\Controllers\AuthController::class)->sendOtp($user, 'email_verification', 10);
            } catch (\Throwable $e) {
                Log::error('Email change OTP failed: ' . $e->getMessage());
            }

            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Email changed successfully. Please verify your new email.',
                'needs_verification' => true,
                'email' => $user->email,
            ]);
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
        ]);
    }
}
