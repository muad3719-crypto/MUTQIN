/* ============================================================
   المصادقة — مُتقِن
   تسجيل الدخول/الخروج، تخزين التوكن والمستخدم، حماية الصفحات حسب الدور.
   ============================================================ */
(function () {
    const C = window.MutqinConfig;

    function saveAuth(token, user) {
        localStorage.setItem(C.STORAGE_TOKEN, token);
        localStorage.setItem(C.STORAGE_USER, JSON.stringify(user));
    }

    function clearAuth() {
        localStorage.removeItem(C.STORAGE_TOKEN);
        localStorage.removeItem(C.STORAGE_USER);
    }

    function getUser() {
        try { return JSON.parse(localStorage.getItem(C.STORAGE_USER)); }
        catch (_) { return null; }
    }

    function isLoggedIn() {
        return !!localStorage.getItem(C.STORAGE_TOKEN);
    }

    // وجهة كل دور بعد الدخول
    function dashboardFor(role) {
        if (role === 'admin')   return C.APP_ROOT + 'admin/dashboard.html';
        if (role === 'teacher') return C.APP_ROOT + 'teacher/dashboard.html';
        if (role === 'parent')  return C.APP_ROOT + 'parent/dashboard.html';
        if (role === 'center_manager') return C.APP_ROOT + 'manager/dashboard.html';
        return C.APP_ROOT + 'login.html';
    }

    function redirectByRole(role) {
        location.href = dashboardFor(role);
    }

    // تسجيل الدخول الموحّد (إيميل + كلمة مرور)
    async function login(email, password) {
        const res = await window.API.post('/auth/login', { email, password });
        const { token, user } = res.data;
        saveAuth(token, user);
        return user;
    }

    async function logout() {
        try { await window.API.post('/auth/logout'); } catch (_) { /* تجاهل */ }
        clearAuth();
        location.href = C.APP_ROOT + 'login.html';
    }

    // حارس الصفحة: استدعها أعلى كل صفحة محمية
    // requireAuth(['admin'])  أو  requireAuth(['teacher','admin'])
    function requireAuth(allowedRoles) {
        if (!isLoggedIn()) {
            location.href = C.APP_ROOT + 'login.html';
            return null;
        }
        const user = getUser();
        if (allowedRoles && allowedRoles.length && !allowedRoles.includes(user.role)) {
            // دور غير مصرّح له → أعِده للوحته
            redirectByRole(user.role);
            return null;
        }
        return user;
    }

    // إن كان مسجّلاً بالفعل، لا تُظهر صفحة الدخول
    function redirectIfLoggedIn() {
        if (isLoggedIn()) {
            const u = getUser();
            if (u && u.role) redirectByRole(u.role);
        }
    }

    window.Auth = {
        login, logout, getUser, isLoggedIn, clearAuth,
        requireAuth, redirectByRole, redirectIfLoggedIn, dashboardFor,
    };
})();
