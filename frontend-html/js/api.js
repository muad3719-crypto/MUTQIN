/* ============================================================
   غلاف الـ API — مُتقِن
   دوال موحّدة (get/post/put/del/upload) مع:
   - إرفاق توكن Bearer تلقائياً
   - معالجة الخطأ 401 (مسح التوكن + تحويل لصفحة الدخول)
   - إرجاع JSON بصيغة { success, message, data, errors }
   ============================================================ */
(function () {
    const { API_BASE_URL } = window.MutqinConfig;

    // كائن خطأ موحّد يحمل الرسالة العربية وقائمة أخطاء الحقول
    class ApiError extends Error {
        constructor(message, status, errors, payload = null) {
            super(message || 'حدث خطأ غير متوقع');
            this.name = 'ApiError';
            this.status = status;
            this.errors = errors || {};
            this.data = payload; // حمولة data من الغلاف (مثل conflicts في 409 الحضور)
        }
    }

    function getToken() {
        return localStorage.getItem(window.MutqinConfig.STORAGE_TOKEN);
    }

    // النواة: تنفيذ أي طلب
    async function request(method, path, body, isUpload) {
        const headers = { 'Accept': 'application/json' };
        const token = getToken();
        if (token) headers['Authorization'] = 'Bearer ' + token;

        const options = { method, headers };

        if (body !== undefined && body !== null) {
            if (isUpload) {
                options.body = body; // FormData — لا نضع Content-Type يدوياً
            } else {
                headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
        }

        let res;
        try {
            res = await fetch(API_BASE_URL + path, options);
        } catch (e) {
            throw new ApiError('تعذّر الاتصال بالخادم. تأكّد أن الـ API يعمل.', 0, {});
        }

        // 401 = توكن غير صالح/منتهٍ → مسح وتحويل للدخول
        if (res.status === 401) {
            localStorage.removeItem(window.MutqinConfig.STORAGE_TOKEN);
            localStorage.removeItem(window.MutqinConfig.STORAGE_USER);
            if (!location.pathname.endsWith('login.html')) {
                location.href = window.MutqinConfig.APP_ROOT + 'login.html';
            }
            throw new ApiError('انتهت الجلسة، يرجى تسجيل الدخول من جديد.', 401, {});
        }

        // محاولة قراءة JSON (قد يكون فارغاً)
        let data = {};
        const text = await res.text();
        if (text) { try { data = JSON.parse(text); } catch (_) { data = {}; } }

        if (!res.ok || data.success === false) {
            throw new ApiError(
                data.message || 'فشل تنفيذ الطلب (' + res.status + ')',
                res.status,
                data.errors || {},
                data.data || null
            );
        }

        return data; // { success, message, data, ... }
    }

    window.API = {
        get:    (path)        => request('GET', path),
        post:   (path, body)  => request('POST', path, body),
        put:    (path, body)  => request('PUT', path, body),
        del:    (path)        => request('DELETE', path),
        upload: (path, formData) => request('POST', path, formData, true),
        ApiError,
    };
})();
