<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OTPMail;
use App\Models\OtpCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Send OTP to the user's email.
     */
    public function sendOTP(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|exists:users,email',
            'password' => 'nullable|string',
        ], [
            'email.exists' => 'هذا البريد الإلكتروني غير مسجل لدينا.',
        ]);

        // If password is provided, check it first (for Login Step 1)
        if ($request->filled('password')) {
            $user = User::where('email', $request->email)->first();
            if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'كلمة المرور غير صحيحة.'], 422);
            }
        }

        $code = rand(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(10);

        OtpCode::where('email', $request->email)->delete();

        OtpCode::create([
            'email' => $request->email,
            'code' => $code,
            'expires_at' => $expiresAt,
        ]);

        // SEND EMAIL (Will go to laravel.log if MAIL_MAILER=log)
        Mail::to($request->email)->send(new OTPMail($code));

        return response()->json([
            'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني بنجاح. يرجى مراجعة البريد.',
        ]);
    }

    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'gender' => 'required|string|in:male,female',
            'password' => 'required|string|min:6',
        ], [
            'email.unique' => 'هذا البريد الإلكتروني مسجل بالفعل.',
            'password.min' => 'كلمة المرور يجب أن لا تقل عن 6 أحرف.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'gender' => $request->gender,
            'password' => $request->password,
            'role' => 'user',
        ]);

        $user->profile()->create();

        // Send initial OTP to email
        $this->sendOTP(new Request(['email' => $user->email]));

        return response()->json([
            'message' => 'تم التسجيل بنجاح. تم إرسال رمز التحقق إلى بريدك الإلكتروني.',
            'email' => $user->email,
        ], 201);
    }

    /**
     * Login/Verify Password and OTP and return token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|exists:users,email',
            'password' => 'required|string',
            'code' => 'required|string',
        ], [
            'email.exists' => 'هذا البريد الإلكتروني غير مسجل.',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        // Check Password
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'كلمة المرور غير صحيحة.'], 422);
        }

        // Check OTP
        $otp = OtpCode::where('email', $request->email)
            ->where('code', $request->code)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otp) {
            return response()->json(['message' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.'], 422);
        }

        $otp->delete();
        
        if ($user->is_banned) {
            return response()->json(['message' => 'هذا الحساب محظور من قبل الإدارة.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('profile'),
        ]);
    }

    /**
     * Logout user.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.']);
    }

    /**
     * Update user password.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
            'password.min' => 'كلمة المرور يجب أن لا تقل عن 8 أحرف.',
        ]);

        $user = $request->user();
        $user->password = bcrypt($request->password);
        $user->save();

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح.']);
    }

    /**
     * Get current user.
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('profile');
        $activeSub = $user->subscriptions()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        $userData = $user->toArray();
        $userData['active_subscription'] = $activeSub;

        return response()->json(['user' => $userData]);
    }
}
