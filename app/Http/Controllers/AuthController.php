<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'role' => 'sometimes|in:customer,seller',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            if ($errors->has('email')) {
                return response()->json([
                    'message' => 'Email already exists. Please use another email address or sign in.',
                    'errors' => $errors,
                ], 422);
            }
            return response()->json(['errors' => $errors], 422);
        }

        $role = $request->role ?? 'customer';

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $role,
            'seller_status' => $role === 'seller' ? 'pending' : null,
        ]);

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::error('Verification email failed to send: ' . $e->getMessage());
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Registration successful. Please verify your email.'
        ], 201);
    }

    public function login(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required',
            ]);

        if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
        }

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'message' => 'Invalid credentials'
                ], 401);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token,
                'message' => 'Login successful'
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'message' => 'Login failed',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'type' => get_class($e)
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // =========================
    // UPDATE PROFILE
    // =========================

    public function updateProfile(Request $request)
    {
        try {

            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'errors' => $validator->errors()
                ], 422);
            }

            $user->name = $request->name;
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->address = $request->address;

            $user->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $user
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================
    // FORGOT PASSWORD
    // =========================

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );

            if ($status === Password::RESET_LINK_SENT) {
                return response()->json(['message' => 'Password reset email sent successfully.']);
            }

            return response()->json(['message' => 'Unable to send reset link'], 500);
        } catch (\Throwable $e) {
            Log::error('Password reset email failed to send: ' . $e->getMessage());
            return response()->json(['message' => 'Password reset email failed to send.'], 500);
        }
    }

    // =========================
    // RESET PASSWORD
    // =========================

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully']);
        }

        return response()->json(['message' => __($status)], 400);
    }

    // =========================
    // EMAIL VERIFICATION
    // =========================

    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid verification link'], 400);
            }
            return redirect(env('FRONTEND_URL', 'http://127.0.0.1:5500') . '/verify-email.html?error=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Email already verified']);
            }
            return redirect(env('FRONTEND_URL', 'http://127.0.0.1:5500') . '/verify-email.html?verified=1');
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Email verified successfully']);
        }

        return redirect(env('FRONTEND_URL', 'http://127.0.0.1:5500') . '/verify-email.html?verified=1');
    }

    public function resendVerificationEmail(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        try {
            $user->sendEmailVerificationNotification();
            return response()->json(['message' => 'Verification email sent successfully.']);
        } catch (\Throwable $e) {
            Log::error('Resend verification email failed: ' . $e->getMessage());
            return response()->json(['message' => 'Verification email failed to send.'], 500);
        }
    }
}