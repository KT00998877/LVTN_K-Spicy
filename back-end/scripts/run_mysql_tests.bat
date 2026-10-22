@echo off
REM Phase P2 v1.2.2 — Run MySQL integration tests.
REM
REM Yêu cầu: MySQL server đang chạy và đã tạo database `chatbot_test`.
REM
REM Setup nhanh (Docker):
REM   docker run -d --name mysql-phobert -e MYSQL_ROOT_PASSWORD=secret -p 3306:3306 mysql:8
REM   docker exec -it mysql-phobert mysql -uroot -psecret -e "CREATE DATABASE chatbot_test;"
REM
REM Sau đó chạy:
REM   scripts\run_mysql_tests.bat

setlocal

if not exist "vendor\autoload.php" (
    echo ERROR: vendor\autoload.php không tồn tại. Chạy "composer install" trước.
    exit /b 1
)

set DB_CONNECTION=mysql
set DB_HOST=127.0.0.1
set DB_PORT=3306
set DB_DATABASE=chatbot_test
set DB_USERNAME=root
set DB_PASSWORD=secret

echo ===========================================================
echo P2 v1.2.2 — MySQL Integration Tests
echo ===========================================================
echo DB: %DB_CONNECTION://%DB_USERNAME:%DB_PASSWORD@%DB_HOST:%DB_PORT%/%DB_DATABASE%
echo.

REM Verify MySQL reachable.
where mysql >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [1/3] Testing MySQL connection...
    mysql -h%DB_HOST% -P%DB_PORT% -u%DB_USERNAME% -p%DB_PASSWORD% -e "SELECT 1" >nul 2>&1
    if errorlevel 1 (
        echo ERROR: Không kết nối được MySQL. Hãy kiểm tra credentials.
        exit /b 1
    )
    echo   OK.
) else (
    echo [1/3] mysql client không có — bỏ qua pre-check.
)

REM Run migrations trên DB test.
echo [2/3] Running migrations trên chatbot_test...
php artisan migrate:fresh --env=testing
if errorlevel 1 (
    echo ERROR: Migration fail.
    exit /b 1
)
echo   OK.

REM Run MySQL tests.
echo [3/3] Running MySqlConcurrencyTest...
php artisan test --filter=MySqlConcurrencyTest
if errorlevel 1 (
    echo ERROR: MySQL tests FAILED.
    exit /b 1
)

echo.
echo ===========================================================
echo ALL MYSQL TESTS PASS
echo ===========================================================
endlocal

