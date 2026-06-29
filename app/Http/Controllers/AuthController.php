<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Otp;
use App\Notifications\SendOtpNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    private function generateOtp(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendOtp(User $user, string $type, int $expiryMinutes = 10): string
    {
        $otp = $this->generateOtp();

        Otp::where('user_id', $user->id)->where('type', $type)->delete();

        Otp::create([
            'user_id' => $user->id,
            'type' => $type,
            'otp' => Hash::make($otp),
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        try {
            Log::info('Sending OTP email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'type' => $type,
            ]);

            $user->notify(new SendOtpNotification($otp, $type));

            Log::info('OTP email sent successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'type' => $type,
            ]);
        } catch (\Throwable $e) {
            Log::error('OTP send failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'type' => $type,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        return $otp;
    }

    private function verifyOtp(User $user, string $type, string $otp): bool
    {
        $record = Otp::where('user_id', $user->id)
            ->where('type', $type)
            ->latest()
            ->first();

        if (!$record || !$record->isValid()) {
            return false;
        }

        return Hash::check($otp, $record->otp);
    }

    // =========================
    // REGISTER
    // =========================

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

        $this->sendOtp($user, 'email_verification', 10);

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'message' => 'Registration successful. Please check your email for the verification code.'
        ], 201);
    }

    // =========================
    // VERIFY EMAIL OTP
    // =========================

    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        if (!$this->verifyOtp($user, 'email_verification', $request->otp)) {
            return response()->json(['message' => 'Invalid or expired verification code'], 400);
        }

        $user->markEmailAsVerified();

        Otp::where('user_id', $user->id)->where('type', 'email_verification')->delete();

        return response()->json(['message' => 'Email verified successfully']);
    }

    // =========================
    // RESEND VERIFICATION OTP
    // =========================

    public function resendOtp(Request $request)
    {
        Log::info('RESEND OTP ENDPOINT HIT');

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        $this->sendOtp($user, 'email_verification', 10);

        return response()->json(['message' => 'Verification code sent successfully.']);
    }

    // =========================
    // LOGIN
    // =========================

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

            if (!$user->hasVerifiedEmail()) {
                $this->sendOtp($user, 'email_verification', 10);

                return response()->json([
                    'message' => 'Please verify your email.',
                    'needs_verification' => true,
                    'email' => $user->email,
                ], 403);
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
            ], 500);
        }
    }

    // =========================
    // LOGOUT
    // =========================

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    // =========================
    // CURRENT USER
    // =========================

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // =========================
    // FORGOT PASSWORD
    // =========================

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = Password::broker()->createToken($user);
            $user->sendPasswordResetNotification($token);
        }

        return response()->json([
            'message' => 'If that email address is registered, you will receive a password reset link shortly.'
        ]);
    }

    // =========================
    // RESET PASSWORD
    // =========================

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully']);
        }

        return response()->json(['message' => 'Invalid or expired reset token'], 400);
    }

    // =========================
    // CHANGE PASSWORD
    // =========================

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 400);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Password changed successfully']);
    }

    // =========================
    // EMAIL VERIFICATION (Legacy signed URL - keep for backward compat)
    // =========================

    public function verifyEmailLegacy(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        $user->markEmailAsVerified();

        return response()->json(['message' => 'Email verified successfully']);
    }
}
