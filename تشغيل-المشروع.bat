@echo off
chcp 65001 >nul
title تشغيل منصة متقن
echo ============================================
echo            تشغيل منصة "مُتقِن"
echo ============================================
echo.

REM --- 1) تأكد من تشغيل MySQL في XAMPP ---
echo [1/3] التحقق من MySQL ...
"C:\xampp\mysql\bin\mysqladmin.exe" -u root status >nul 2>&1
if errorlevel 1 (
  echo     - MySQL لا يعمل. جاري تشغيله ...
  start "" /min "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini"
  timeout /t 5 >nul
) else (
  echo     - MySQL يعمل بالفعل.
)
echo.

REM --- 2) تشغيل الـ API (الخلفية) على المنفذ 9090 ---
echo [2/3] تشغيل الـ API على http://localhost:9090 ...
start "Mutqin API (9090)" cmd /k "cd /d C:\xampp\htdocs\MUTQENQ\backend && php artisan serve --port=9090"

REM --- 3) تشغيل الموقع (الواجهة) على المنفذ 9091 ---
echo [3/3] تشغيل الموقع على http://localhost:9091 ...
start "Mutqin Web (9091)" cmd /k "cd /d C:\xampp\htdocs\MUTQENQ\frontend && php artisan serve --port=9091"

echo.
echo تم التشغيل. انتظر ثانيتين ثم سيُفتح الموقع تلقائياً ...
timeout /t 3 >nul
start "" http://localhost:9091/login

echo.
echo ============================================
echo  الموقع     : http://localhost:9091
echo  الـ API    : http://localhost:9090/api
echo  للإيقاف    : أغلق نافذتي الـ API والموقع
echo ============================================
echo.
pause
