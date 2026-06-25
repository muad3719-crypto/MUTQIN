# مُتقِن — الواجهة المستقلة (frontend-html)

واجهة أمامية مستقلة بـ **HTML + CSS + JavaScript + Bootstrap 5 RTL**، تتصل بالـ API فقط عبر HTTP/JSON. لا PHP ولا Blade.

## ⚙️ الإعداد
عنوان الـ API في ملف واحد: **`js/config.js`**
```js
const API_BASE_URL = 'http://localhost:9090/api';
```
غيّره إن غيّرت منفذ الـ backend.

## ▶️ التشغيل تحت XAMPP
ضع مجلد `frontend-html/` داخل `htdocs` (موجود فعلاً)، ثم شغّل:
1. **الـ API (Laravel):** `cd backend && php artisan serve --port=9090` + تشغيل **MySQL**.
2. **الواجهة:** أي خادم ساكن. مثلاً:
   - عبر Apache (XAMPP): `http://localhost/MUTQENQ/frontend-html/`
   - أو خادم PHP: `cd frontend-html && php -S localhost:8080` ثم `http://localhost:8080/`

> ملاحظة CORS: الـ API يسمح بكل المصادر (`allowed_origins: ['*']`)، فالاتصال من أي منفذ يعمل.

## 🔑 حسابات تجريبية (كلمة المرور: `password`)
| الدور | البريد |
|---|---|
| مدير | `admin@mutqin.ly` |
| معلم | `teacher1@mutqin.ly` |
| ولي أمر | `parent1@mutqin.ly` |

## 🗂️ البنية
```
index.html              الصفحة الرئيسية العامة (إحصائيات من /public/stats)
login.html              دخول موحّد (يحوّل حسب الدور)
admin/                  dashboard · centers · teachers · students  (CRUD)
teacher/                dashboard · students · attendance · memorization · weekly-tests
parent/                 dashboard (الأبناء) · child (تفاصيل، قراءة فقط)
css/theme.css           الهوية (أخضر/ذهبي/عاجي، Amiri+Cairo)
js/config.js            API_BASE_URL + جذر الموقع
js/api.js               غلاف fetch (Bearer، 401→دخول، {success,data,errors})
js/auth.js              دخول/خروج، تخزين التوكن، حماية الأدوار
js/ui.js               تنبيهات، نوافذ النماذج، شارات، بحث، إظهار كلمة المرور
js/layout.js            الشريط الجانبي/العلوي حسب الدور
```

## 🔐 الأمان
- التوكن يُخزَّن في `localStorage` ويُرسَل `Authorization: Bearer <token>` مع كل طلب.
- عند الخطأ 401: يُمسح التوكن ويُحوَّل المستخدم لصفحة الدخول.
- كل صفحة محميّة تستدعي `Auth.requireAuth([roles])` للحماية حسب الدور.
