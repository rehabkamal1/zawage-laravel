<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Arial', sans-serif; background-color: #f4f7f4; padding: 20px; text-align: center; }
        .container { background-color: #ffffff; border-radius: 15px; padding: 40px; max-width: 500px; margin: 0 auto; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 5px solid #2e7d32; }
        .logo { font-size: 32px; font-weight: bold; color: #2e7d32; margin-bottom: 20px; }
        .title { font-size: 22px; color: #333; margin-bottom: 15px; font-weight: bold; }
        .otp-code { font-size: 40px; font-weight: 800; color: #2e7d32; letter-spacing: 8px; background: #e8f5e9; padding: 20px 40px; border-radius: 12px; display: inline-block; margin: 25px 0; border: 2px dashed #2e7d32; }
        .instruction { color: #555; line-height: 1.6; font-size: 16px; }
        .footer { font-size: 13px; color: #999; margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px; }
        .accent { color: #fbc02d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">منصه نصفي الاخر  <span class="accent">للـزواج</span></div>
        <div class="title">رمز التحقق الخاص بك</div>
        <p class="instruction">شكراً لانضمامك إلينا. استخدم الكود التالي لإتمام عملية تسجيل الدخول:</p>
        <div class="otp-code">{{ $code }}</div>
        <p class="instruction">هذا الرمز صالح لمدة <strong>10 دقائق</strong> فقط.<br>إذا لم تطلب هذا الرمز، يمكنك تجاهل هذه الرسالة بأمان.</p>
        <div class="footer">
            &copy; 2026 منصة نصفي الاخر للتوفيق بين الزوجين<br>
            نحن هنا لمساعدتك في العثور على شريك  حياتك.
        </div>
    </div>
</body>
</html>
