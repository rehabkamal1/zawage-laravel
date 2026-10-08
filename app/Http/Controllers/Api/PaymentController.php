<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Initialize subscription and initiate Paymob payment.
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:daily,weekly,monthly',
            'payment_method' => 'required|string|in:vodafone_cash,instapay',
            'wallet_number' => 'required_if:payment_method,vodafone_cash|string|max:15',
        ], [
            'type.in' => 'نوع الاشتراك يجب أن يكون يومي (daily)، أسبوعي (weekly) أو شهري (monthly).',
            'payment_method.in' => 'طريقة الدفع يجب أن تكون فودافون كاش (vodafone_cash) أو إنستا باي (instapay).',
            'wallet_number.required_if' => 'رقم المحفظة مطلوب عند اختيار الدفع عبر فودافون كاش.',
        ]);

        $user = $request->user();
        $type = $request->type;
        $paymentMethod = $request->payment_method;

        // Pricing and limits configuration
        if ($type === 'daily') {
            $amount = 50.00;
            $viewsAllowed = 3;
        } elseif ($type === 'weekly') {
            $amount = 100.00;
            $viewsAllowed = 7;
        } else {
            $amount = 300.00;
            $viewsAllowed = 25;
        }

        // 1. Create a pending subscription
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => $type,
            'views_allowed' => $viewsAllowed,
            'views_used' => 0,
            'expires_at' => now()->addMinutes(15), // temporary until activated
            'status' => 'pending',
        ]);

        // 2. Create a pending payment record
        $payment = Payment::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'status' => 'pending',
        ]);

        // Check for Paymob keys configuration.
        $apiKey = env('PAYMOB_API_KEY');
        $integrationId = $paymentMethod === 'vodafone_cash' 
            ? env('PAYMOB_INTEGRATION_ID_VODAFONE') 
            : env('PAYMOB_INTEGRATION_ID_INSTAPAY');
        $iframeId = env('PAYMOB_IFRAME_ID');

        $isProduction = app()->environment('production');

        if (!$apiKey || !$integrationId) {
            if ($isProduction) {
                return response()->json([
                    'message' => 'عذراً، بيانات تهيئة بوابة الدفع (Paymob) غير مكتملة على الخادم.',
                ], 500);
            }

            // Mock sandbox mode (only for local/testing)
            $mockOrderId = 'MOCK_ORDER_' . rand(100000, 999999);
            $payment->update(['paymob_order_id' => $mockOrderId]);

            return response()->json([
                'message' => 'تم إنشاء طلب الاشتراك بنجاح (وضع تجربة الدفع النشط)',
                'is_mock' => true,
                'subscription_id' => $subscription->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'checkout_url' => url("/api/subscriptions/{$subscription->id}/simulate-payment-page"),
            ]);
        }

        try {
            // Paymob Real Integration Flow
            // Step 1: Authentication
            $authResponse = Http::post('https://accept.paymob.com/api/auth/tokens', [
                'api_key' => $apiKey
            ]);

            if ($authResponse->failed()) {
                throw new \Exception('Paymob Authentication Failed: ' . $authResponse->body());
            }

            $authToken = $authResponse->json()['token'];

            // Step 2: Order Registration
            $orderResponse = Http::post('https://accept.paymob.com/api/ecommerce/orders', [
                'auth_token' => $authToken,
                'delivery_needed' => 'false',
                'amount_cents' => (int)($amount * 100),
                'currency' => 'EGP',
                'items' => [
                    [
                        'name' => "Zawage Subscription - " . ucfirst($type),
                        'amount_cents' => (int)($amount * 100),
                        'quantity' => 1
                    ]
                ]
            ]);

            if ($orderResponse->failed()) {
                throw new \Exception('Paymob Order Registration Failed: ' . $orderResponse->body());
            }

            $paymobOrderId = $orderResponse->json()['id'];
            $payment->update(['paymob_order_id' => $paymobOrderId]);

            // Split name for billing data
            $nameParts = explode(' ', $user->name, 2);
            $firstName = $nameParts[0] ?? 'User';
            $lastName = $nameParts[1] ?? 'Name';

            // Step 3: Payment Key Generation
            $paymentKeyResponse = Http::post('https://accept.paymob.com/api/acceptance/payment_keys', [
                'auth_token' => $authToken,
                'amount_cents' => (int)($amount * 100),
                'expiration' => 3600,
                'order_id' => $paymobOrderId,
                'billing_data' => [
                    'apartment' => 'NA',
                    'email' => $user->email,
                    'floor' => 'NA',
                    'first_name' => $firstName,
                    'street' => 'NA',
                    'building' => 'NA',
                    'phone_number' => $user->phone ?? '01000000000',
                    'shipping_method' => 'PKG',
                    'postal_code' => 'NA',
                    'city' => 'Cairo',
                    'country' => 'EG',
                    'last_name' => $lastName,
                    'state' => 'Cairo'
                ],
                'currency' => 'EGP',
                'integration_id' => (int)$integrationId
            ]);

            if ($paymentKeyResponse->failed()) {
                throw new \Exception('Paymob Payment Key Generation Failed: ' . $paymentKeyResponse->body());
            }

            $paymentKey = $paymentKeyResponse->json()['token'];

            // Step 4: Method-specific Checkout URL
            if ($paymentMethod === 'vodafone_cash') {
                // Mobile Wallet Checkout
                $walletResponse = Http::post('https://accept.paymob.com/api/acceptance/payments/pay', [
                    'source' => [
                        'identifier' => $request->wallet_number,
                        'subtype' => 'WALLET'
                    ],
                    'payment_token' => $paymentKey
                ]);

                if ($walletResponse->failed()) {
                    throw new \Exception('Paymob Wallet Pay Request Failed: ' . $walletResponse->body());
                }

                $checkoutUrl = $walletResponse->json()['iframe_redirection_url'] 
                    ?? $walletResponse->json()['redirect_url'] 
                    ?? $walletResponse->json()['pending_url'] 
                    ?? '';
            } else {
                // Card / InstaPay Checkout Iframe
                $checkoutUrl = "https://accept.paymob.com/api/acceptance/iframes/{$iframeId}?payment_token={$paymentKey}";
            }

            return response()->json([
                'message' => 'تم إنشاء طلب الدفع بنجاح.',
                'is_mock' => false,
                'subscription_id' => $subscription->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'checkout_url' => $checkoutUrl,
            ]);

        } catch (\Exception $e) {
            Log::error('Paymob Integration Error: ' . $e->getMessage());

            if ($isProduction) {
                return response()->json([
                    'message' => 'فشلت عملية تهيئة الدفع مع Paymob. يرجى المحاولة مرة أخرى لاحقاً أو التواصل مع الدعم الفني.',
                    'error_details' => app()->environment('local', 'testing') ? $e->getMessage() : null,
                ], 500);
            }

            // Fallback to mock so developers don't get stuck if server/keys fail (only in local/testing)
            $mockOrderId = 'MOCK_ORDER_ERR_' . rand(100000, 999999);
            $payment->update(['paymob_order_id' => $mockOrderId]);

            return response()->json([
                'message' => 'فشلت تهيئة الدفع مع Paymob، تم الانتقال تلقائياً لوضع محاكاة الدفع لتسهيل الاختبار.',
                'error_details' => $e->getMessage(),
                'is_mock' => true,
                'subscription_id' => $subscription->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'checkout_url' => url("/api/subscriptions/{$subscription->id}/simulate-payment-page"),
            ]);
        }
    }

    /**
     * Display a simple HTML interface to simulate a sandbox payment.
     */
    public function simulatePaymentPage($subscriptionId)
    {
        $subscription = Subscription::findOrFail($subscriptionId);
        $payment = Payment::where('subscription_id', $subscriptionId)->firstOrFail();

        if (app()->environment('production') && !str_starts_with($payment->paymob_order_id ?? '', 'MOCK_ORDER')) {
            abort(403, 'غير مسموح بوضع المحاكاة في البيئة الإنتاجية للعمليات الحقيقية.');
        }

        $user = $subscription->user;

        $activationUrl = url("/api/subscriptions/{$subscriptionId}/simulate-payment");

        return response("
            <!DOCTYPE html>
            <html lang='ar' dir='rtl'>
            <head>
                <meta charset='UTF-8'>
                <title>بوابة محاكاة الدفع - Paymob Sandbox</title>
                <link href='https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&display=swap' rel='stylesheet'>
                <style>
                    body {
                        font-family: 'Tajawal', sans-serif;
                        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        height: 100vh;
                        margin: 0;
                    }
                    .card {
                        background: white;
                        padding: 40px;
                        border-radius: 24px;
                        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                        max-width: 450px;
                        width: 100%;
                        text-align: center;
                    }
                    h1 { color: #2d3748; margin-bottom: 20px; font-weight: 900; }
                    .details {
                        background: #f7fafc;
                        border-radius: 16px;
                        padding: 20px;
                        margin-bottom: 30px;
                        text-align: right;
                        border: 1px solid #edf2f7;
                    }
                    .details div {
                        margin-bottom: 10px;
                        display: flex;
                        justify-content: space-between;
                    }
                    .details div span:first-child { color: #718096; }
                    .details div span:last-child { font-weight: 700; color: #2d3748; }
                    .btn {
                        background: #48bb78;
                        color: white;
                        border: none;
                        padding: 16px 30px;
                        font-size: 1.1rem;
                        font-weight: 700;
                        border-radius: 12px;
                        cursor: pointer;
                        width: 100%;
                        transition: background 0.2s;
                        box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
                    }
                    .btn:hover { background: #38a169; }
                    .btn-cancel {
                        background: #e53e3e;
                        margin-top: 10px;
                        box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
                    }
                    .btn-cancel:hover { background: #c53030; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <h1>محاكاة دفع Paymob 💳</h1>
                    <p style='color: #718096; margin-bottom: 30px;'>أنت الآن في بوابة محاكاة الدفع التجريبية الخاصة بـ <strong>InstaPay / Vodafone Cash</strong></p>
                    
                    <div class='details'>
                        <div><span>اسم المشترك:</span> <span>{$user->name}</span></div>
                        <div><span>نوع الاشتراك:</span> <span>" . ($subscription->type === 'daily' ? 'يومي (3 محاولات)' : ($subscription->type === 'weekly' ? 'أسبوعي (7 محاولات)' : 'شهري (25 محاولة)')) . "</span></div>
                        <div><span>المبلغ المستحق:</span> <span>{$payment->amount} جنيه مصري</span></div>
                        <div><span>طريقة الدفع:</span> <span>" . ($payment->payment_method === 'vodafone_cash' ? 'فودافون كاش' : 'إنستا باي') . "</span></div>
                        <div><span>حالة المعاملة:</span> <span style='color: #dd6b20;'>قيد الانتظار</span></div>
                    </div>

                    <form action='{$activationUrl}' method='POST'>
                        <button type='submit' class='btn'>تأكيد الدفع بنجاح (سداد المقابل) ✔</button>
                    </form>
                </div>
            </body>
            </html>
        ", 200);
    }

    /**
     * Endpoint to directly simulate/activate subscription payment.
     */
    public function simulatePayment(Request $request, $subscriptionId)
    {
        $subscription = Subscription::findOrFail($subscriptionId);
        $payment = Payment::where('subscription_id', $subscriptionId)->firstOrFail();

        if (app()->environment('production') && !str_starts_with($payment->paymob_order_id ?? '', 'MOCK_ORDER')) {
            abort(403, 'غير مسموح بوضع المحاكاة في البيئة الإنتاجية للعمليات الحقيقية.');
        }

        // Update Payment to completed
        $payment->update([
            'status' => 'completed',
            'paymob_transaction_id' => 'TXN_MOCK_' . rand(1000000, 9999999),
        ]);

        // Update Subscription to active
        if ($subscription->type === 'daily') {
            $days = 1;
        } elseif ($subscription->type === 'weekly') {
            $days = 7;
        } else {
            $days = 30;
        }
        $subscription->update([
            'status' => 'active',
            'expires_at' => now()->addDays($days),
        ]);

        return response("
            <!DOCTYPE html>
            <html lang='ar' dir='rtl'>
            <head>
                <meta charset='UTF-8'>
                <title>تم الدفع بنجاح</title>
                <link href='https://fonts.googleapis.com/css2?family=Tajawal:wght@700&display=swap' rel='stylesheet'>
                <style>
                    body {
                        font-family: 'Tajawal', sans-serif;
                        background: #f7fafc;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        height: 100vh;
                        margin: 0;
                    }
                    .card {
                        background: white;
                        padding: 50px;
                        border-radius: 24px;
                        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
                        max-width: 400px;
                        width: 100%;
                        text-align: center;
                    }
                    .icon {
                        font-size: 70px;
                        color: #48bb78;
                        margin-bottom: 20px;
                    }
                    h1 { color: #2d3748; margin-bottom: 10px; }
                    p { color: #718096; line-height: 1.6; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='icon'>🎉</div>
                    <h1>تم الدفع وتفعيل الاشتراك بنجاح!</h1>
                    <p>لقد تم تفعيل اشتراكك الـ " . ($subscription->type === 'daily' ? 'يومي' : ($subscription->type === 'weekly' ? 'أسبوعي' : 'شهري')) . " بنجاح. يمكنك الآن ملء استمارة الزواج والبحث عن شريك حياتك والتواصل معه.</p>
                    <p style='margin-top: 20px; font-weight: bold; color: #4c3a7a;'>يمكنك إغلاق هذه الصفحة والعودة للتطبيق الآن.</p>
                </div>
            </body>
            </html>
        ", 200);
    }

    /**
     * Paymob webhook callback endpoint.
     */
    public function handleCallback(Request $request)
    {
        Log::info('Paymob callback received', $request->all());

        $hmacSecret = env('PAYMOB_HMAC_SECRET');
        $signature = $request->query('hmac');

        if (!$signature) {
            return response()->json(['message' => 'Missing signature'], 400);
        }

        // Validate webhook signature
        $data = $request->all();
        $obj = $data['obj'] ?? null;

        if (!$obj) {
            return response()->json(['message' => 'Invalid data payload'], 400);
        }

        // HMAC Concatenation string construction
        $hmacString = '';
        $keys = [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
        ];

        foreach ($keys as $key) {
            $val = $obj[$key] ?? '';
            // Booleans inside JSON payload must be matched exactly as string representational value
            if (is_bool($val)) {
                $hmacString .= $val ? 'true' : 'false';
            } else {
                $hmacString .= $val;
            }
        }

        $hmacString .= $obj['order']['id'] ?? '';
        $hmacString .= $obj['owner'] ?? '';
        
        $pending = $obj['pending'] ?? '';
        $hmacString .= is_bool($pending) ? ($pending ? 'true' : 'false') : $pending;
        
        $hmacString .= $obj['source_data']['pan'] ?? '';
        $hmacString .= $obj['source_data']['sub_type'] ?? '';
        $hmacString .= $obj['source_data']['type'] ?? '';
        
        $success = $obj['success'] ?? '';
        $hmacString .= is_bool($success) ? ($success ? 'true' : 'false') : $success;

        $calculatedHmac = hash_hmac('sha512', $hmacString, $hmacSecret);

        if ($calculatedHmac !== $signature) {
            Log::warning('Paymob HMAC mismatch verification failed.');
            return response()->json(['message' => 'HMAC verification failed'], 401);
        }

        $paymobOrderId = $obj['order']['id'] ?? null;
        $transactionId = $obj['id'] ?? null;
        $isSuccess = $obj['success'] ?? false;

        if ($paymobOrderId) {
            $payment = Payment::where('paymob_order_id', $paymobOrderId)->first();

            if ($payment) {
                if ($isSuccess) {
                    // Update Payment to completed
                    $payment->update([
                        'status' => 'completed',
                        'paymob_transaction_id' => $transactionId,
                    ]);

                    // Update Subscription to active
                    $subscription = $payment->subscription;
                    if ($subscription) {
                        if ($subscription->type === 'daily') {
                            $days = 1;
                        } elseif ($subscription->type === 'weekly') {
                            $days = 7;
                        } else {
                            $days = 30;
                        }
                        $subscription->update([
                            'status' => 'active',
                            'expires_at' => now()->addDays($days),
                        ]);
                    }

                    Log::info("Payment & Subscription activated successfully for Paymob Order: {$paymobOrderId}");
                } else {
                    $payment->update([
                        'status' => 'failed',
                        'paymob_transaction_id' => $transactionId,
                    ]);
                    Log::warning("Payment marked as failed for Paymob Order: {$paymobOrderId}");
                }
            }
        }

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Submit manual Vodafone Cash transfer receipt.
     */
    public function manualTransfer(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:daily,weekly,monthly',
            'receipt_image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            'sender_wallet_number' => 'nullable|string|max:20',
        ], [
            'type.in' => 'نوع الاشتراك يجب أن يكون يومي (daily)، أسبوعي (weekly) أو شهري (monthly).',
            'receipt_image.required' => 'يرجى إرفاق صورة إيصال التحويل.',
            'receipt_image.image' => 'الملف المرفق يجب أن يكون صورة.',
            'receipt_image.max' => 'حجم الصورة يجب ألا يتعدى 5 ميجابايت.',
        ]);

        $user = $request->user();
        $type = $request->type;

        if ($type === 'daily') {
            $amount = 50.00;
            $viewsAllowed = 3;
        } elseif ($type === 'weekly') {
            $amount = 100.00;
            $viewsAllowed = 7;
        } else {
            $amount = 300.00;
            $viewsAllowed = 25;
        }

        // Save receipt image
        $path = $request->file('receipt_image')->store('receipts', 'public');

        // Create pending subscription
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => $type,
            'views_allowed' => $viewsAllowed,
            'views_used' => 0,
            'expires_at' => now()->addDays(1),
            'status' => 'pending',
        ]);

        // Create pending payment record
        $payment = Payment::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'payment_method' => 'vodafone_cash_manual',
            'status' => 'pending',
            'receipt_image' => $path,
            'sender_wallet_number' => $request->sender_wallet_number,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'تم إرسال إيصال التحويل بنجاح وهو الآن قيد المراجعة من الإدارة.',
            'subscription' => $subscription,
            'payment' => $payment,
        ], 201);
    }
}
