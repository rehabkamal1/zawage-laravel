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
     * Send OTP via WhatsApp or Email.
     */
    protected function sendWhatsAppOtp($phone, $code)
    {
        \Illuminate\Support\Facades\Log::info("WhatsApp OTP for [{$phone}]: {$code}");
        // Ready for WhatsApp Gateway integration (UltraMsg / Twilio / Meta API)
    }

    /**
     * Send OTP to the user's email or phone.
     */
    public function sendOTP(Request $request)
    {
        $authMethod = $request->input('auth_method', $request->filled('phone') ? 'phone' : 'email');

        if ($authMethod === 'phone') {
            $request->validate([
                'phone' => 'required|string|digits:11|exists:users,phone',
                'password' => 'nullable|string',
            ], [
                'phone.exists' => 'رقم الهاتف هذا غير مسجل لدينا.',
                'phone.digits' => 'رقم الهاتف يجب أن يتكون من 11 رقماً.',
            ]);
            $user = User::where('phone', $request->phone)->first();
            $identifier = $request->phone;
        } else {
            $request->validate([
                'email' => 'required|string|email|exists:users,email',
                'password' => 'nullable|string',
            ], [
                'email.exists' => 'هذا البريد الإلكتروني غير مسجل لدينا.',
            ]);
            $user = User::where('email', $request->email)->first();
            $identifier = $request->email;
        }

        // If password is provided, check it first (for Login Step 1)
        if ($request->filled('password')) {
            if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'كلمة المرور غير صحيحة.'], 422);
            }
            if ($user->is_banned) {
                return response()->json(['message' => 'هذا الحساب محظور من قبل الإدارة.'], 403);
            }
        }

        $code = rand(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(10);

        OtpCode::where('email', $identifier)->delete();

        OtpCode::create([
            'email' => $identifier,
            'code' => $code,
            'expires_at' => $expiresAt,
        ]);

        if ($authMethod === 'phone') {
            $this->sendWhatsAppOtp($identifier, $code);
            return response()->json([
                'message' => 'تم إرسال كود التحقق عبر الواتساب بنجاح.',
                'identifier' => $identifier,
            ]);
        } else {
            Mail::to($identifier)->send(new OTPMail($code));
            return response()->json([
                'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني بنجاح.',
                'identifier' => $identifier,
            ]);
        }
    }

    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $authMethod = $request->input('auth_method', 'phone');

        $rules = [
            'name' => 'nullable|string|max:255',
            'gender' => 'required|string|in:male,female',
            'password' => 'required|string|min:6',
        ];

        if ($authMethod === 'phone') {
            $rules['phone'] = 'required|string|digits:11|unique:users,phone';
        } else {
            $rules['email'] = 'required|string|email|max:255|unique:users,email';
        }

        $request->validate($rules, [
            'email.unique' => 'هذا البريد الإلكتروني مسجل بالفعل.',
            'phone.unique' => 'رقم الهاتف هذا مسجل بالفعل.',
            'phone.digits' => 'رقم الهاتف يجب أن يتكون من 11 رقماً.',
            'password.min' => 'كلمة المرور يجب أن لا تقل عن 6 أحرف.',
        ]);

        $defaultName = $request->filled('name') ? $request->name : ('عضو_' . rand(10000, 99999));

        $user = User::create([
            'name' => $defaultName,
            'email' => $authMethod === 'email' ? $request->email : null,
            'phone' => $authMethod === 'phone' ? $request->phone : null,
            'gender' => $request->gender,
            'password' => $request->password,
            'role' => 'user',
        ]);

        $user->profile()->create([
            'full_name'     => $defaultName,
            'phone'         => $authMethod === 'phone' ? $request->phone : '',
            'dob'           => '1990-01-01',
            'governorate'   => '',
            'area'          => '',
            'address'       => '',
            'education'     => '',
            'job'           => '',
            'income'        => '',
            'accommodation' => '',
            'prayer'        => '',
            'hijab'         => '',
            'smoking'       => '',
        ]);

        $identifier = $authMethod === 'phone' ? $user->phone : $user->email;

        $code = rand(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(10);

        OtpCode::where('email', $identifier)->delete();
        OtpCode::create([
            'email' => $identifier,
            'code' => $code,
            'expires_at' => $expiresAt,
        ]);

        if ($authMethod === 'phone') {
            $this->sendWhatsAppOtp($identifier, $code);
        } else {
            Mail::to($identifier)->send(new OTPMail($code));
        }

        return response()->json([
            'message' => 'تم التسجيل بنجاح. تم إرسال كود التحقق.',
            'identifier' => $identifier,
            'auth_method' => $authMethod,
        ], 201);
    }

    /**
     * Login/Verify Password and OTP and return token.
     */
    public function login(Request $request)
    {
        $authMethod = $request->input('auth_method', $request->filled('phone') ? 'phone' : 'email');
        $identifier = $authMethod === 'phone' ? $request->phone : $request->email;

        $request->validate([
            'password' => 'required|string',
            'code' => 'nullable|string',
        ]);

        if ($authMethod === 'phone') {
            $user = User::where('phone', $identifier)->firstOrFail();
        } else {
            $user = User::where('email', $identifier)->firstOrFail();
        }

        // Check Password
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'كلمة المرور غير صحيحة.'], 422);
        }

        // Check OTP only if code is provided (for initial registration verification)
        if ($request->filled('code')) {
            $otp = OtpCode::where('email', $identifier)
                ->where('code', $request->code)
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if (!$otp) {
                return response()->json(['message' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.'], 422);
            }

            $otp->delete();
        }
        
        if ($user->is_banned) {
            return response()->json(['message' => 'هذا الحساب محظور من قبل الإدارة.'], 403);
        }

        $activeSub = $user->subscriptions()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        $latestPayment = $user->payments()
            ->with('subscription')
            ->latest()
            ->first();

        $userData = $user->load('profile')->toArray();
        $userData['active_subscription'] = $activeSub;
        $userData['latest_payment'] = $latestPayment;

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $userData,
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

        $latestPayment = $user->payments()
            ->with('subscription')
            ->latest()
            ->first();

        $userData = $user->toArray();
        $userData['active_subscription'] = $activeSub;
        $userData['latest_payment'] = $latestPayment;

        return response()->json(['user' => $userData]);
    }
}
