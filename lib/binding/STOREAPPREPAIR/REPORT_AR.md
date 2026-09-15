# STOREAPP — إصلاح البنية الحالية

تم إعداد هذه الحزمة بعد إعادة فحص المستودع على commit:
`4310d3902558d5f82c1ce8876709259401c8b939`

## النتيجة
معظم كود Dart موجود الآن، لكنه مرفوع في جذر المستودع بدل `lib/`.
الحزمة تعيد تكوين:
- `lib/binding`
- `lib/controller`
- `lib/core`
- `lib/data`
- `lib/view/address`
- `lib/view/orders`
- `lib/view/screeen`
- `lib/view/widget`

وتستعيد ملفات الدخول الناقصة من commit المصدر المطابق:
`lib/main.dart`, `lib/routes.dart`, `lib/linkapi.dart`, `lib/binding.dart`, `lib/test.dart`.

كما تصلح import خاطئ في `routes.dart`، وتجعل عنوان API قابلًا للضبط عبر `API_BASE_URL`، وتضيف GitHub Actions لبناء APK تجريبي قابل للتثبيت.

## التنفيذ داخل Codespace
انسخ محتويات الحزمة إلى جذر المستودع ثم:

```bash
python3 repair_storeapp.py
bash tools/build_apk.sh
```

الناتج المتوقع:
`dist/STOREAPP-debug.apk`

## ما يبقى بعد نجاح أول APK
1. تحويل Release من debug signing إلى keystore ثابت.
2. تدوير مفاتيح Gemini وGoogle Maps المكتوبة في المصدر العام.
3. ربط `API_BASE_URL` بخادم PHP/MySQL فعلي عبر HTTPS؛ العنوان الأصلي `192.168.130.157` محلي فقط.
4. إضافة مفاتيح Google Maps المقيدة وFirebase production settings.
5. تشغيل اختبارات السلة/الطلبات/المصادقة والإشعارات على جهاز حقيقي.
