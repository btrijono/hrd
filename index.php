<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Koneksi database
$conn = new mysqli("localhost", "root", "", "form_app");

if ($conn->connect_error) {
    $db_error = "⚠️ Koneksi database gagal: " . $conn->connect_error;
} else {
    // Buat tabel users jika belum ada
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE,
        password VARCHAR(255),
        full_name VARCHAR(100),
        role VARCHAR(50)
    )");
    
    // Insert user HRD default jika belum ada
    $check_hrd = $conn->query("SELECT * FROM users WHERE username = 'hrd'");
    if ($check_hrd->num_rows == 0) {
        $hashed_password = password_hash('julong123!', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO users (username, password, full_name, role) VALUES ('hrd', '$hashed_password', 'HRD Julong', 'admin')");
    }
    
    $conn->query("CREATE TABLE IF NOT EXISTS form_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        datetime DATETIME,
        photo VARCHAR(255),
        action VARCHAR(10),
        latitude VARCHAR(50),
        longitude VARCHAR(50),
        accuracy VARCHAR(50),
        sync_status VARCHAR(20) DEFAULT 'synced'
    )");
    
    // Cek dan tambahkan kolom accuracy jika belum ada
    $check_accuracy = $conn->query("SHOW COLUMNS FROM form_entries LIKE 'accuracy'");
    if ($check_accuracy->num_rows == 0) {
        $conn->query("ALTER TABLE form_entries ADD COLUMN accuracy VARCHAR(50) AFTER longitude");
    }
    
    // Cek dan tambahkan kolom sync_status jika belum ada
    $check_sync = $conn->query("SHOW COLUMNS FROM form_entries LIKE 'sync_status'");
    if ($check_sync->num_rows == 0) {
        $conn->query("ALTER TABLE form_entries ADD COLUMN sync_status VARCHAR(20) DEFAULT 'synced'");
    }
}

// Inisialisasi session
session_start();

// Variabel login
$login_error = '';
$login_success = false;
$isLoggedIn = false;

// Proses login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_submit'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($conn && !$conn->connect_error) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;
                $login_success = true;
                $isLoggedIn = true;
            } else {
                $login_error = "❌ Password salah!";
            }
        } else {
            $login_error = "❌ Username tidak ditemukan!";
        }
        $stmt->close();
    } else {
        $login_error = "❌ Koneksi database gagal!";
    }
}

// Proses logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Cek session login
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $isLoggedIn = true;
}

// Inisialisasi variabel
$last_submitted_data = null;
$form_disabled = false;

// Proses form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {
    $first = $_POST['first_name'] ?? '';
    $last = $_POST['last_name'] ?? '';
    $date = $_POST['date'] ?? date('Y-m-d');
    $time = $_POST['time'] ?? date('H:i');
    $datetime = date("Y-m-d H:i:s", strtotime("$date $time"));
    $action = $_POST['action'] ?? '';
    $lat = $_POST['latitude'] ?? '';
    $lng = $_POST['longitude'] ?? '';
    $accuracy = $_POST['accuracy'] ?? '';
    $photo_path = null;
    $photo_data_for_popup = null;

    // Validasi lokasi jika tidak diisi
    if (empty($lat) || empty($lng)) {
        $lat = '-6.2146';
        $lng = '106.8451';
        $accuracy = '0';
    }

    // Proses penyimpanan foto
    if (!empty($_POST['photo_data'])) {
        $photo_data_for_popup = $_POST['photo_data'];

        $dir = "uploads/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $parts = explode(";base64,", $_POST['photo_data']);
        if (count($parts) == 2) {
            $img = base64_decode($parts[1]);
            if ($img !== false) {
                $gdImage = imagecreatefromstring($img);
                if ($gdImage) {
                    $width = imagesx($gdImage);
                    $height = imagesy($gdImage);
                    
                    $maxSize = 480;
                    if ($width > $height) {
                        $newWidth = $maxSize;
                        $newHeight = floor($height * ($maxSize / $width));
                    } else {
                        $newHeight = $maxSize;
                        $newWidth = floor($width * ($maxSize / $height));
                    }
                    
                    $newImage = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($newImage, $gdImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    
                    $name = time() . '_' . uniqid() . '.jpg';
                    $photo_path = $dir . $name;
                    imagejpeg($newImage, $photo_path, 60);
                    
                    imagedestroy($gdImage);
                    imagedestroy($newImage);
                } else {
                    $name = time() . '_' . uniqid() . '.jpg';
                    $photo_path = $dir . $name;
                    file_put_contents($photo_path, $img);
                }
            } else {
                $error = "❌ Gagal memproses data foto.";
            }
        } else {
            $error = "❌ Data foto tidak valid.";
        }
    } else {
        $error = "❌ Foto selfie wajib diambil!";
    }

    if ($conn && !$conn->connect_error && !isset($error)) {
        $stmt = $conn->prepare("INSERT INTO form_entries (first_name, last_name, datetime, photo, action, latitude, longitude, accuracy, sync_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced')");
        $stmt->bind_param("ssssssss", $first, $last, $datetime, $photo_path, $action, $lat, $lng, $accuracy);

        if ($stmt->execute()) {
            $success = "✅ Data berhasil disimpan!";
            $last_submitted_data = [
                'first_name' => $first,
                'last_name' => $last,
                'action' => $action,
                'photo_data' => $photo_data_for_popup,
                'datetime' => $datetime,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => $accuracy
            ];
            $form_disabled = true;
        } else {
            $error = "❌ Gagal menyimpan ke database: " . $stmt->error;
        }
        $stmt->close();
    } elseif (!isset($error)) {
        $error = "❌ Mode offline: data tidak tersimpan. Periksa koneksi database Anda.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#008000">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Absensi">
    <link rel="manifest" href="/web_project/essy/manifest.json">
    <link rel="icon" href="/web_project/essy/icon-192.png" type="image/png">
    <link rel="apple-touch-icon" href="/web_project/essy/icon-192.png">
    
    <title>Absensi HRD Julong</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        html, body {
            height: 100%;
            width: 100%;
            background: #008000;
        }
        
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        
        .app-container {
            width: 100%;
            max-width: 450px;
            height: 100vh;
            max-height: -webkit-fill-available;
            background: white;
            box-shadow: 0 0 30px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        @media (min-width: 450px) {
            .app-container {
                height: 90vh;
                border-radius: 32px;
                margin: 20px auto;
            }
        }
        
        .app-header {
            background: #008000;
            padding: 16px 16px 8px;
            text-align: center;
            border-bottom: 3px solid #ffd700;
            position: relative;
            flex-shrink: 0;
        }
        
        .app-header h1 {
            color: white;
            font-size: 20px;
            margin-bottom: 2px;
        }
        
        .app-header p {
            color: #ffd700;
            font-size: 11px;
        }
        
        .user-info {
            position: absolute;
            top: 8px;
            right: 12px;
            color: #ffd700;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .user-info i {
            font-size: 12px;
        }
        
        .logout-btn {
            background: none;
            border: 1px solid #ffd700;
            color: #ffd700;
            padding: 3px 6px;
            border-radius: 12px;
            font-size: 9px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            background: #ffd700;
            color: #008000;
        }
        
        .login-header-btn {
            position: absolute;
            top: 8px;
            right: 12px;
            background: #ffd700;
            color: #008000;
            border: none;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        
        .login-header-btn i {
            font-size: 10px;
        }
        
        .main-menu {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: #f8fafd;
            padding: 20px;
        }
        
        .icons-container {
            display: flex;
            gap: 20px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .menu-icon {
            flex: 0 0 auto;
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0,128,0,0.15);
            border: 2px solid #ffd700;
        }
        
        .menu-icon.active {
            opacity: 1;
            cursor: pointer;
        }
        
        .menu-icon.active:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,128,0,0.25);
            background: #008000;
        }
        
        .menu-icon.active:hover i,
        .menu-icon.active:hover span {
            color: white;
        }
        
        .menu-icon.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(0.7);
            border-color: #cccccc;
            background: #f0f0f0;
        }
        
        .menu-icon i {
            font-size: 32px;
            color: #008000;
        }
        
        .menu-icon span {
            font-size: 12px;
            font-weight: 600;
            color: #008000;
        }
        
        .menu-icon.disabled i,
        .menu-icon.disabled span {
            color: #999999;
        }
        
        .install-prompt {
            background: linear-gradient(135deg, #008000, #006400);
            border: 2px solid #ffd700;
            border-radius: 30px;
            padding: 12px 20px;
            margin: 30px auto 0;
            display: none;
            justify-content: space-between;
            align-items: center;
            width: 90%;
            max-width: 320px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3); }
            50% { box-shadow: 0 8px 25px rgba(255, 215, 0, 0.6); }
            100% { box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3); }
        }
        
        .install-prompt span {
            color: #ffd700;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .install-prompt span i {
            font-size: 18px;
        }
        
        .install-prompt button {
            background: #ffd700;
            color: #008000;
            border: none;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .install-prompt button:hover {
            background: #ffed4a;
            transform: scale(1.05);
        }
        
        .install-prompt button i {
            margin-right: 5px;
        }
        
        .manual-install {
            background: #008000;
            color: white;
            border: 2px solid #ffd700;
            border-radius: 30px;
            padding: 10px 20px;
            margin: 15px auto 0;
            display: none;
            justify-content: center;
            align-items: center;
            gap: 10px;
            width: 90%;
            max-width: 320px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .manual-install:hover {
            background: #006400;
            transform: scale(1.02);
        }
        
        .manual-install i {
            color: #ffd700;
            font-size: 18px;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 16px;
        }
        
        .login-modal {
            background: white;
            border-radius: 24px;
            max-width: 320px;
            width: 100%;
            padding: 20px 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            text-align: center;
            border: 3px solid #ffd700;
            animation: slideUp 0.3s ease;
        }
        
        .login-modal h2 {
            color: #008000;
            margin-bottom: 16px;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .login-modal h2 i {
            color: #ffd700;
        }
        
        .login-input-group {
            margin-bottom: 12px;
            text-align: left;
        }
        
        .login-input-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #008000;
            margin-bottom: 3px;
        }
        
        .login-input-group label i {
            color: #ffd700;
            width: 16px;
        }
        
        .login-input-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #dde3e9;
            border-radius: 12px;
            font-size: 13px;
        }
        
        .login-input-group input:focus {
            border-color: #ffd700;
            outline: none;
        }
        
        .login-btn {
            width: 100%;
            background: #008000;
            color: white;
            border: 2px solid #ffd700;
            padding: 10px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            margin-top: 8px;
        }
        
        .login-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 8px;
            border-radius: 25px;
            font-size: 12px;
            text-align: center;
            margin-bottom: 12px;
        }
        
        .modal-close {
            margin-top: 12px;
            color: #94a3b8;
            font-size: 12px;
            cursor: pointer;
        }
        
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .form-modal {
            background: white;
            border-radius: 24px;
            max-width: 400px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            border: 3px solid #ffd700;
            animation: slideUp 0.3s ease;
            position: relative;
        }
        
        .form-modal h2 {
            color: #008000;
            margin-bottom: 15px;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #ffd700;
            padding-bottom: 10px;
        }
        
        .form-modal h2 i {
            color: #ffd700;
            cursor: pointer;
        }
        
        .form-modal .close-btn {
            cursor: pointer;
        }
        
        .time-info {
            background: #f0f9ff;
            border: 1px solid #008000;
            border-radius: 16px;
            padding: 10px;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .current-time {
            font-size: 24px;
            font-weight: 700;
            color: #008000;
            margin-bottom: 5px;
        }
        
        .time-status {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
            font-weight: 600;
        }
        
        .status-clockin { background: #22c55e; color: white; }
        .status-clockout { background: #ef4444; color: white; }
        .status-closed { background: #94a3b8; color: white; }
        
        .alert {
            padding: 8px 12px;
            border-radius: 25px;
            margin-bottom: 10px;
            text-align: center;
            font-weight: 500;
            font-size: 12px;
        }
        
        .alert-success { background: #22c55e; color: white; }
        .alert-error { background: #ef4444; color: white; }
        .alert-warning { background: #f59e0b; color: white; }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .row {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }
        
        .group {
            flex: 1;
        }
        
        .group label {
            display: block;
            font-size: 10px;
            font-weight: 600;
            color: #008000;
            margin-bottom: 2px;
        }
        
        .group label i {
            color: #ffd700;
            width: 14px;
            font-size: 10px;
        }
        
        input, select {
            width: 100%;
            padding: 8px 10px;
            border: 1.5px solid #dde3e9;
            border-radius: 12px;
            font-size: 12px;
            background: white;
        }
        
        input:focus {
            border-color: #008000;
            outline: none;
        }
        
        input[readonly] {
            background: #eef2f6;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        
        .camera-box {
            background: white;
            border: 2px solid #dde3e9;
            border-radius: 16px;
            padding: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .camera-box.warning {
            border: 2px solid #ef4444;
            background: #fee2e2;
            animation: shake 0.5s;
        }
        
        .camera-box label {
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .camera-box label i { color: #ffd700; }
        
        .camera-box label .required-badge {
            background: #ef4444;
            color: white;
            font-size: 8px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        .preview-area {
            width: 100%;
            height: 140px;
            background: #1e293b;
            border-radius: 12px;
            overflow: hidden;
            margin: 6px 0;
            border: 2px solid #008000;
            position: relative;
        }
        
        #video, #fotoResult {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        
        #video { transform: scaleX(-1); }
        
        #loadingCamera {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            background: #1e293b;
            z-index: 10;
            font-size: 12px;
        }
        
        .btn-group {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        
        .btn {
            border: none;
            padding: 6px 12px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #008000;
            color: white;
            border: 1px solid #ffd700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn:hover:not(:disabled) {
            background: #006400;
            transform: scale(1.02);
        }
        
        .btn-danger {
            background: #b91c1c;
        }
        
        .btn-danger:hover:not(:disabled) {
            background: #991b1b;
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .selfie-status {
            font-size: 10px;
            margin-top: 4px;
            padding: 4px;
            border-radius: 12px;
            text-align: center;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        
        .radio-group {
            background: white;
            padding: 8px;
            border-radius: 12px;
            border: 1.5px solid #dde3e9;
            display: flex;
            gap: 10px;
        }
        
        .radio-group label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            font-weight: 500;
            color: #008000;
            font-size: 11px;
            cursor: pointer;
            flex: 1;
            justify-content: center;
            padding: 5px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        
        .radio-group label.active-clockin {
            background: #22c55e20;
            border: 1px solid #22c55e;
        }
        
        .radio-group label.active-clockout {
            background: #ef444420;
            border: 1px solid #ef4444;
        }
        
        .radio-group label.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #e5e7eb;
        }
        
        .radio-group input {
            width: auto;
            margin-right: 2px;
            cursor: pointer;
        }
        
        .radio-group input:disabled { cursor: not-allowed; }
        
        .clock-in { color: #16a34a; font-weight: 600; }
        .clock-out { color: #dc2626; font-weight: 600; }
        
        .location-container {
            background: white;
            border: 1.5px solid #dde3e9;
            border-radius: 16px;
            padding: 10px;
            margin-bottom: 10px;
            border-left: 4px solid #16a34a;
        }
        
        .location-coords {
            display: flex;
            gap: 5px;
            margin-bottom: 6px;
        }
        
        .location-coords input {
            flex: 1;
            background: #f0fdf4;
            border-color: #86efac;
            color: #166534;
            font-weight: 500;
            padding: 6px 8px;
            font-size: 11px;
        }
        
        .location-info {
            display: flex;
            align-items: center;
            font-size: 10px;
            color: #64748b;
        }
        
        .location-info i {
            margin-right: 4px;
            color: #ffd700;
            font-size: 10px;
        }
        
        .submit-btn {
            width: 100%;
            background: #008000;
            color: white;
            border: 2px solid #ffd700;
            padding: 10px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 13px;
            margin-top: 8px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .submit-btn:hover:not(:disabled) {
            background: #006400;
            transform: scale(1.02);
        }
        
        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #94a3b8;
        }
        
        .submit-btn.selfie-missing {
            animation: shake 0.5s;
            background: #ef4444;
        }
        
        .success-modal-content {
            background: white;
            border-radius: 24px;
            max-width: 320px;
            width: 100%;
            padding: 20px 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            text-align: center;
            border: 3px solid #ffd700;
            animation: slideUp 0.3s ease;
        }
        
        .success-modal-content h2 {
            color: #008000;
            margin-bottom: 12px;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .success-modal-content h2 i { color: #22c55e; }
        
        .modal-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #ffd700;
            margin: 5px auto 15px;
            display: block;
            background: #f1f5f9;
        }
        
        .modal-detail {
            background: #f8fafd;
            padding: 12px;
            border-radius: 16px;
            margin-bottom: 15px;
            text-align: left;
            font-size: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .modal-detail p {
            margin: 6px 0;
            display: flex;
            align-items: center;
            border-bottom: 1px dashed #cbd5e1;
            padding-bottom: 3px;
        }
        
        .modal-detail p:last-child { border-bottom: none; }
        
        .modal-detail i {
            width: 20px;
            color: #ffd700;
            font-size: 13px;
            margin-right: 6px;
        }
        
        .modal-detail .label {
            font-weight: 600;
            color: #008000;
            min-width: 60px;
            font-size: 11px;
        }
        
        .modal-detail .value {
            color: #334155;
            flex: 1;
            text-align: right;
            font-weight: 500;
            font-size: 11px;
        }
        
        .modal-close-btn {
            background: #008000;
            color: white;
            border: 2px solid #ffd700;
            padding: 8px 25px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            width: fit-content;
            margin: 0 auto;
            transition: all 0.2s;
        }
        
        .modal-close-btn:hover { background: #006400; }
        
        .fa-spinner { animation: spin 1s linear infinite; }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .cache-info {
            font-size: 10px;
            color: #64748b;
            text-align: center;
            margin: 10px auto;
            padding: 3px 8px;
            background: #f1f5f9;
            border-radius: 16px;
            display: inline-block;
        }
        
        .camera-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }
        
        .camera-loading i {
            font-size: 24px;
            color: #ffd700;
        }
        
        .camera-loading span {
            font-size: 11px;
            color: white;
        }
        
        .quick-camera-btn {
            background: #ffd700;
            color: #008000;
            border: none;
            padding: 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 5px;
            width: 100%;
        }
        
        .quick-camera-btn:hover {
            background: #ffed4a;
        }
        
        .status-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .status-online {
            background: #22c55e;
            color: white;
        }
        
        .status-offline {
            background: #ef4444;
            color: white;
        }
        
        /* Offline indicator */
        .offline-indicator {
            position: fixed;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: #ef4444;
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            z-index: 999;
            display: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>
    <!-- Offline Indicator -->
    <div id="offlineIndicator" class="offline-indicator">
        <i class="fas fa-wifi"></i> Mode Offline - Data disimpan lokal
    </div>

    <div class="app-container">
        <div class="app-header">
            <h1>HRD JULONG</h1>
            <p>Form Pengganti Absensi Sementara</p>
            
            <?php if ($isLoggedIn): ?>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']) ?></span>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Yakin ingin logout?')">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            <?php else: ?>
                <button class="login-header-btn" onclick="openLoginModal()">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            <?php endif; ?>
        </div>

        <div class="main-menu" id="mainMenu">
            <div class="icons-container">
                <div class="menu-icon active" onclick="openFormModal('absen')" id="absenIcon">
                    <i class="fas fa-camera"></i>
                    <span>ABSEN</span>
                </div>
                
                <div class="menu-icon <?= $isLoggedIn ? 'active' : 'disabled' ?>" 
                     onclick="<?= $isLoggedIn ? "window.location.href='report.php'" : "openLoginModal()" ?>" 
                     id="reportIcon">
                    <i class="fas fa-chart-bar"></i>
                    <span>REPORT</span>
                </div>
                
                <div class="menu-icon <?= $isLoggedIn ? 'active' : 'disabled' ?>" 
                     onclick="<?= $isLoggedIn ? "window.location.href='map.php'" : "openLoginModal()" ?>" 
                     id="mapIcon">
                    <i class="fas fa-map-marked-alt"></i>
                    <span>MAP</span>
                </div>
            </div>

            <div class="install-prompt" id="installPrompt" style="display: none;">
                <span><i class="fas fa-download"></i> Install Aplikasi</span>
                <button id="installBtn"><i class="fas fa-download"></i> Install</button>
            </div>

            <div class="manual-install" id="manualInstallBtn" onclick="manualInstall()">
                <i class="fas fa-download"></i>
                <span>Install Aplikasi ke Home Screen</span>
            </div>

            <div id="cacheInfo" class="cache-info" style="display: none;">
                <i class="fas fa-history"></i> <span id="cacheName"></span>
            </div>
            
            <div id="pendingCount" class="cache-info" style="display: none; background: #fef9c3;">
                <i class="fas fa-clock"></i> <span id="pendingText">0 data pending sync</span>
            </div>
        </div>

        <!-- MODAL FORM ABSENSI -->
        <div id="formModal" class="modal-overlay" style="display: none;">
            <div class="form-modal">
                <h2>
                    <span><i class="fas fa-camera" style="color: #ffd700;"></i> Form Absensi</span>
                    <i class="fas fa-times close-btn" onclick="closeFormModal()"></i>
                </h2>

                <div class="time-info">
                    <div class="current-time" id="currentTimeDisplay">--:--:--</div>
                    <div id="timeStatusDisplay" class="time-status status-clockin">Memuat...</div>
                </div>

                <?php if (isset($db_error)): ?>
                    <div class="alert alert-error"><?= $db_error ?></div>
                <?php endif; ?>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-error"><?= $error ?></div>
                <?php endif; ?>

                <?php if ($form_disabled): ?>
                <div class="form-locked-info" style="background:#fef9c3; padding:6px 10px; border-radius:25px; margin-bottom:10px; text-align:center;">
                    <i class="fas fa-check-circle"></i> Absensi telah selesai
                    <button onclick="clearCacheAndReload()" style="background:#ef4444; color:white; border:none; padding:4px 10px; border-radius:20px; font-size:10px; margin-left:6px; cursor:pointer;">
                        <i class="fas fa-redo"></i> Form Baru
                    </button>
                </div>
                <?php endif; ?>

                <form method="post" id="formAbsen">
                    <input type="hidden" name="submit" value="1">
                    <input type="hidden" name="accuracy" id="accuracy" value="">

                    <div class="row">
                        <div class="group">
                            <label><i class="fas fa-user"></i> Nama Lengkap</label>
                            <input type="text" name="first_name" id="first_name" required 
                                   placeholder="Nama" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" 
                                   <?= $form_disabled ? 'disabled' : '' ?>>
                        </div>
                        <div class="group">
                            <label><i class="fas fa-user"></i> Departemen</label>
                            <input type="text" name="last_name" id="last_name" required 
                                   placeholder="Dept" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" 
                                   <?= $form_disabled ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div class="row">
                        <div class="group">
                            <label><i class="fas fa-calendar"></i> Tanggal</label>
                            <input type="date" name="date" id="tglInput" required 
                                   value="<?= date('Y-m-d') ?>" readonly 
                                   <?= $form_disabled ? 'disabled' : '' ?>>
                        </div>
                        <div class="group">
                            <label><i class="fas fa-clock"></i> Jam</label>
                            <input type="time" name="time" id="waktuInput" required 
                                   value="<?= date('H:i') ?>" readonly 
                                   <?= $form_disabled ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <!-- Camera Section - OPTIMIZED -->
                    <div class="camera-box" id="cameraBox">
                        <label>
                            <i class="fas fa-camera"></i> Selfie 
                            <span class="required-badge">WAJIB</span>
                        </label>

                        <div class="preview-area">
                            <div id="loadingCamera">
                                <div class="camera-loading">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <span>Mengaktifkan kamera...</span>
                                </div>
                            </div>
                            <video id="video" autoplay playsinline style="display:none;"></video>
                            <img id="fotoResult" src="#" alt="foto" style="display:none;">
                        </div>

                        <div class="btn-group">
                            <button type="button" class="btn" id="ambilFotoBtn" disabled>
                                <i class="fas fa-camera"></i> Ambil Selfie
                            </button>
                            <button type="button" class="btn btn-danger" id="ulangFotoBtn" style="display:none;">
                                <i class="fas fa-redo"></i> Ulang
                            </button>
                        </div>

                        <button type="button" class="quick-camera-btn" id="quickCameraBtn" style="display:none;" onclick="quickStartCamera()">
                            <i class="fas fa-sync-alt"></i> Coba Aktifkan Kamera Lagi
                        </button>

                        <input type="hidden" name="photo_data" id="photoData">
                        
                        <div id="selfieStatus" class="selfie-status status-pending">
                            <i class="fas fa-exclamation-triangle"></i> WAJIB ambil selfie terlebih dahulu
                        </div>
                    </div>

                    <!-- Action Radio - Clock Out FLEKSIBEL -->
                    <div class="group" style="margin-bottom:8px;">
                        <label><i class="fas fa-tag"></i> Absensi</label>
                        <div class="radio-group" id="radioGroup">
                            <label id="clockInLabel">
                                <input type="radio" name="action" value="Login" id="clockInRadio" required 
                                       <?= $form_disabled ? 'disabled' : '' ?>> 
                                <span class="clock-in"><i class="fas fa-sign-in-alt"></i> Clock In</span>
                                <small style="display:block; font-size:8px; color:#666;">05:00 - 12:00</small>
                            </label>
                            <label id="clockOutLabel">
                                <input type="radio" name="action" value="Logout" id="clockOutRadio" required 
                                       <?= $form_disabled ? 'disabled' : '' ?>> 
                                <span class="clock-out"><i class="fas fa-sign-out-alt"></i> Clock Out</span>
                                <small style="display:block; font-size:8px; color:#666;">Setelah 05:00</small>
                            </label>
                        </div>
                        <div id="timeWarning" style="font-size:10px; color:#ef4444; text-align:center; margin-top:4px; display:none;">
                            <i class="fas fa-exclamation-triangle"></i> <span></span>
                        </div>
                    </div>

                    <!-- Location GPS -->
                    <div class="location-container">
                        <div class="location-header">
                            <label><i class="fas fa-map-marker-alt"></i> Lokasi GPS</label>
                        </div>
                        
                        <div class="location-coords">
                            <input type="text" name="latitude" id="lat" readonly placeholder="Latitude" 
                                   value="<?= htmlspecialchars($_POST['latitude'] ?? '-6.2146') ?>" 
                                   <?= $form_disabled ? 'disabled' : '' ?>>
                            <input type="text" name="longitude" id="lng" readonly placeholder="Longitude" 
                                   value="<?= htmlspecialchars($_POST['longitude'] ?? '106.8451') ?>" 
                                   <?= $form_disabled ? 'disabled' : '' ?>>
                        </div>
                        
                        <div class="location-info" id="lokasiInfo">
                            <i class="fas fa-map-pin"></i> 
                            <span id="akurasiText">
                                <?php if ($form_disabled && isset($_POST['accuracy'])): ?>
                                    Lokasi tersimpan (akurasi ±<?= $_POST['accuracy'] ?>m)
                                <?php else: ?>
                                    Mendeteksi lokasi...
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn" <?= $form_disabled ? 'disabled' : '' ?>>
                        <i class="fas fa-paper-plane"></i> KIRIM ABSENSI
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Login -->
    <div id="loginModal" class="modal-overlay" style="display: none;">
        <div class="login-modal">
            <h2><i class="fas fa-lock"></i> Login HRD</h2>
            
            <?php if ($login_error && isset($_POST['login_submit'])): ?>
            <div class="login-error">
                <i class="fas fa-exclamation-circle"></i> <?= $login_error ?>
            </div>
            <?php endif; ?>
            
            <form method="post" id="loginForm">
                <input type="hidden" name="login_submit" value="1">
                
                <div class="login-input-group">
                    <label><i class="fas fa-user"></i> Username</label>
                    <input type="text" name="username" placeholder="hrd" required autocomplete="off" autofocus>
                </div>
                
                <div class="login-input-group">
                    <label><i class="fas fa-key"></i> Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                
                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i> LOGIN
                </button>
            </form>
            
            <div class="modal-close" onclick="closeLoginModal()">
                <i class="fas fa-times"></i> Tutup
            </div>
        </div>
    </div>

    <!-- Modal Success -->
    <div id="successModal" class="modal-overlay" style="display: none;">
        <div class="success-modal-content">
            <h2><i class="fas fa-check-circle"></i> Absen Berhasil!</h2>
            <img id="modalPhoto" src="" alt="Foto Selfie" class="modal-photo">
            <div class="modal-detail">
                <p>
                    <i class="fas fa-user"></i>
                    <span class="label">Nama:</span>
                    <span class="value" id="modalNama">-</span>
                </p>
                <p>
                    <i class="fas fa-tag"></i>
                    <span class="label">Status:</span>
                    <span class="value" id="modalStatus">-</span>
                </p>
                <p>
                    <i class="fas fa-clock"></i>
                    <span class="label">Waktu:</span>
                    <span class="value" id="modalWaktu">-</span>
                </p>
                <p>
                    <i class="fas fa-map-marker-alt"></i>
                    <span class="label">Lokasi:</span>
                    <span class="value" id="modalLokasi">-</span>
                </p>
                <p>
                    <i class="fas fa-bullseye"></i>
                    <span class="label">Akurasi:</span>
                    <span class="value" id="modalAkurasi">-</span>
                </p>
            </div>
            <div class="modal-close-btn" id="modalCloseBtn">Tutup</div>
        </div>
    </div>

    <script>
        // ========== DATA DARI PHP ==========
        const lastSubmittedData = <?= json_encode($last_submitted_data) ?>;
        const formDisabled = <?= json_encode($form_disabled) ?>;
        const isLoggedIn = <?= json_encode($isLoggedIn) ?>;
        const loginSuccess = <?= json_encode($login_success) ?>;
        
        // ========== VARIABEL GLOBAL ==========
        let cameraInitialized = false;
        let jamTimer = null;
        let gpsAlreadyLoaded = false;
        let cameraStream = null;
        let timeCheckTimer = null;
        let selfieTaken = false;
        let cameraStartAttempts = 0;
        let cameraTimeout = null;
        let dbReady = false;
        const MAX_CAMERA_ATTEMPTS = 3;

        // ========== INDEXEDDB - OFFLINE STORAGE ==========
        class OfflineDB {
            constructor() {
                this.dbName = 'AbsensiDB';
                this.version = 2;
                this.db = null;
            }

            async init() {
                return new Promise((resolve, reject) => {
                    const request = indexedDB.open(this.dbName, this.version);
                    
                    request.onupgradeneeded = (e) => {
                        const db = e.target.result;
                        
                        if (!db.objectStoreNames.contains('absensi')) {
                            const store = db.createObjectStore('absensi', { 
                                keyPath: 'id', 
                                autoIncrement: true 
                            });
                            store.createIndex('sync_status', 'sync_status', { unique: false });
                            store.createIndex('datetime', 'datetime', { unique: false });
                        }
                    };
                    
                    request.onsuccess = (e) => {
                        this.db = e.target.result;
                        dbReady = true;
                        resolve(this.db);
                    };
                    
                    request.onerror = (e) => {
                        reject(e.target.error);
                    };
                });
            }

            async saveData(data) {
                return new Promise((resolve, reject) => {
                    const transaction = this.db.transaction(['absensi'], 'readwrite');
                    const store = transaction.objectStore('absensi');
                    
                    const request = store.add({
                        ...data,
                        sync_status: 'pending',
                        created_at: new Date().toISOString()
                    });
                    
                    request.onsuccess = () => resolve(request.result);
                    request.onerror = () => reject(request.error);
                });
            }

            async getPendingData() {
                return new Promise((resolve, reject) => {
                    const transaction = this.db.transaction(['absensi'], 'readonly');
                    const store = transaction.objectStore('absensi');
                    const index = store.index('sync_status');
                    const request = index.getAll('pending');
                    
                    request.onsuccess = () => resolve(request.result);
                    request.onerror = () => reject(request.error);
                });
            }

            async markAsSynced(id) {
                return new Promise((resolve, reject) => {
                    const transaction = this.db.transaction(['absensi'], 'readwrite');
                    const store = transaction.objectStore('absensi');
                    
                    const request = store.get(id);
                    request.onsuccess = () => {
                        const data = request.result;
                        if (data) {
                            data.sync_status = 'synced';
                            const updateRequest = store.put(data);
                            updateRequest.onsuccess = () => resolve();
                            updateRequest.onerror = () => reject(updateRequest.error);
                        } else {
                            resolve();
                        }
                    };
                    request.onerror = () => reject(request.error);
                });
            }

            async getAllData() {
                return new Promise((resolve, reject) => {
                    const transaction = this.db.transaction(['absensi'], 'readonly');
                    const store = transaction.objectStore('absensi');
                    const request = store.getAll();
                    
                    request.onsuccess = () => resolve(request.result);
                    request.onerror = () => reject(request.error);
                });
            }
        }

        const offlineDB = new OfflineDB();

        // ========== FUNGSI MODAL ==========
        function openLoginModal() {
            document.getElementById('loginModal').style.display = 'flex';
            setTimeout(() => {
                document.querySelector('#loginModal input[name="username"]').focus();
            }, 100);
        }
        
        function closeLoginModal() {
            document.getElementById('loginModal').style.display = 'none';
        }

        // ========== CEK IZIN KAMERA ==========
        async function checkCameraPermission() {
            try {
                if (navigator.permissions && navigator.permissions.query) {
                    const result = await navigator.permissions.query({ name: 'camera' });
                    if (result.state === 'denied') {
                        alert('⚠️ Izin kamera ditolak. Silakan izinkan kamera di pengaturan browser.\n\n' +
                              'Chrome: Settings > Privacy > Camera\n' +
                              'Safari: Settings > Safari > Camera');
                        return false;
                    }
                    return true;
                }
                return true;
            } catch (error) {
                return true;
            }
        }

        // ========== OPEN FORM MODAL ==========
        async function openFormModal(type) {
            if (type === 'absen') {
                // Cek izin kamera dulu
                const hasPermission = await checkCameraPermission();
                if (!hasPermission) {
                    return;
                }
                
                document.getElementById('formModal').style.display = 'flex';
                
                // Reset status
                selfieTaken = false;
                cameraStartAttempts = 0;
                updateSelfieStatus();
                
                // Update waktu dan status
                updateTimeAndStatus();
                if (timeCheckTimer) clearInterval(timeCheckTimer);
                timeCheckTimer = setInterval(updateTimeAndStatus, 1000);
                
                // ===== PRIORITAS KAMERA =====
                setTimeout(() => {
                    if (!formDisabled) {
                        // MULAI KAMERA DULUAN
                        initCamera();
                        
                        // GPS dan jam jalan di background
                        setTimeout(() => {
                            initGPS();
                            initJam();
                        }, 1000);
                    }
                }, 200);
            }
        }

        function closeFormModal() {
            document.getElementById('formModal').style.display = 'none';
            if (timeCheckTimer) {
                clearInterval(timeCheckTimer);
                timeCheckTimer = null;
            }
            if (jamTimer) {
                clearInterval(jamTimer);
                jamTimer = null;
            }
            if (cameraTimeout) {
                clearTimeout(cameraTimeout);
                cameraTimeout = null;
            }
            stopCamera();
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => {
                    track.stop();
                });
                cameraStream = null;
            }
            cameraInitialized = false;
        }

        // ========== UPDATE STATUS SELFIE ==========
        function updateSelfieStatus() {
            const selfieStatus = document.getElementById('selfieStatus');
            const photoData = document.getElementById('photoData').value;
            const fotoResult = document.getElementById('fotoResult');
            const cameraBox = document.getElementById('cameraBox');
            
            if (photoData && photoData !== '' && fotoResult.style.display === 'block') {
                selfieTaken = true;
                selfieStatus.className = 'selfie-status status-success';
                selfieStatus.innerHTML = '<i class="fas fa-check-circle"></i> Selfie sudah diambil ✓';
                cameraBox.classList.remove('warning');
            } else {
                selfieTaken = false;
                selfieStatus.className = 'selfie-status status-pending';
                selfieStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> WAJIB ambil selfie terlebih dahulu';
                cameraBox.classList.add('warning');
            }
        }

        // ========== FUNGSI CEK WAKTU ==========
        function updateTimeAndStatus() {
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const currentHour = hours + ':' + minutes + ':' + seconds;
            
            document.getElementById('currentTimeDisplay').innerHTML = currentHour;
            
            const hourDecimal = now.getHours() + now.getMinutes() / 60;
            const clockInLabel = document.getElementById('clockInLabel');
            const clockOutLabel = document.getElementById('clockOutLabel');
            const clockInRadio = document.getElementById('clockInRadio');
            const clockOutRadio = document.getElementById('clockOutRadio');
            const timeStatusDisplay = document.getElementById('timeStatusDisplay');
            const timeWarning = document.getElementById('timeWarning');
            const warningSpan = timeWarning?.querySelector('span');
            
            // Clock In: 05:00 - 12:00
            // Clock Out: FLEKSIBEL (setelah 05:00)
            
            const isClockInTime = hourDecimal >= 5.0 && hourDecimal <= 12.0;
            const isClockOutTime = hourDecimal >= 5.0;
            
            clockInLabel.classList.remove('active-clockin', 'disabled');
            clockOutLabel.classList.remove('active-clockout', 'disabled');
            
            if (isClockInTime) {
                timeStatusDisplay.className = 'time-status status-clockin';
                timeStatusDisplay.innerHTML = '<i class="fas fa-sign-in-alt"></i> Waktu Clock In';
                if (timeWarning) timeWarning.style.display = 'none';
                
                clockInRadio.disabled = false;
                clockOutRadio.disabled = false;
                clockInLabel.classList.add('active-clockin');
                clockOutLabel.classList.remove('disabled');
                
                if (!clockInRadio.checked && !clockOutRadio.checked) {
                    clockInRadio.checked = true;
                }
                
            } else if (isClockOutTime) {
                timeStatusDisplay.className = 'time-status status-clockout';
                timeStatusDisplay.innerHTML = '<i class="fas fa-sign-out-alt"></i> Waktu Clock Out';
                if (timeWarning) timeWarning.style.display = 'none';
                
                clockInRadio.disabled = true;
                clockOutRadio.disabled = false;
                clockOutLabel.classList.add('active-clockout');
                clockInLabel.classList.add('disabled');
                
                if (!clockInRadio.checked && !clockOutRadio.checked) {
                    clockOutRadio.checked = true;
                }
                
            } else {
                timeStatusDisplay.className = 'time-status status-closed';
                timeStatusDisplay.innerHTML = '<i class="fas fa-clock"></i> Belum Waktu Absen';
                if (timeWarning) {
                    timeWarning.style.display = 'block';
                    if (warningSpan) warningSpan.innerHTML = 'Absensi dimulai pukul 05:00.';
                }
                
                clockInRadio.disabled = true;
                clockOutRadio.disabled = true;
                clockInLabel.classList.add('disabled');
                clockOutLabel.classList.add('disabled');
            }
        }
        
        // ========== EVENT CLICK MODAL ==========
        window.addEventListener('click', (e) => {
            const loginModal = document.getElementById('loginModal');
            if (e.target === loginModal) {
                loginModal.style.display = 'none';
            }
            
            const formModal = document.getElementById('formModal');
            if (e.target === formModal) {
                closeFormModal();
            }
            
            const successModal = document.getElementById('successModal');
            if (e.target === successModal) {
                successModal.style.display = 'none';
            }
        });
        
        if (loginSuccess) {
            window.location.reload();
        }

        // ========== FUNGSI GPS ==========
        function initGPS() {
            if (formDisabled || gpsAlreadyLoaded) return;
            
            const latField = document.getElementById('lat');
            const lngField = document.getElementById('lng');
            const accuracyField = document.getElementById('accuracy');
            const akurasiSpan = document.getElementById('akurasiText');
            
            if (!navigator.geolocation) {
                akurasiSpan.innerHTML = 'GPS tidak didukung';
                return;
            }
            
            gpsAlreadyLoaded = true;
            
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude.toFixed(6);
                    const lng = position.coords.longitude.toFixed(6);
                    const akurasi = position.coords.accuracy.toFixed(0);
                    
                    latField.value = lat;
                    lngField.value = lng;
                    accuracyField.value = akurasi;
                    
                    let akurasiText = `📍 ${lat}, ${lng}`;
                    if (akurasi <= 10) {
                        akurasiText += ` (Sangat Akurat ±${akurasi}m)`;
                    } else if (akurasi <= 30) {
                        akurasiText += ` (Akurat ±${akurasi}m)`;
                    } else {
                        akurasiText += ` (Kurang Akurat ±${akurasi}m)`;
                    }
                    
                    akurasiSpan.innerHTML = akurasiText;
                },
                (error) => {
                    akurasiSpan.innerHTML = `📍 -6.214620, 106.845100 (Default Jakarta)`;
                    accuracyField.value = '0';
                },
                {
                    enableHighAccuracy: true,
                    timeout: 8000,
                    maximumAge: 60000
                }
            );
        }

        // ========== FUNGSI KAMERA - OPTIMIZED & STABIL ==========
        function initCamera() {
            if (formDisabled) return;
            
            const video = document.getElementById('video');
            const fotoResult = document.getElementById('fotoResult');
            const ambilBtn = document.getElementById('ambilFotoBtn');
            const ulangBtn = document.getElementById('ulangFotoBtn');
            const photoData = document.getElementById('photoData');
            const loadingCamera = document.getElementById('loadingCamera');
            const quickBtn = document.getElementById('quickCameraBtn');
            
            if (!video || !fotoResult || !ambilBtn || !ulangBtn || !photoData || !loadingCamera) {
                console.error('Elemen kamera tidak ditemukan');
                return;
            }

            // Tampilkan loading
            loadingCamera.style.display = 'flex';
            loadingCamera.innerHTML = '<div class="camera-loading"><i class="fas fa-spinner fa-spin"></i><span>Mengaktifkan kamera...</span></div>';
            ambilBtn.disabled = true;
            ambilBtn.style.opacity = '0.5';
            quickBtn.style.display = 'none';

            // ===== TIMEOUT KAMERA (15 detik) =====
            if (cameraTimeout) clearTimeout(cameraTimeout);
            cameraTimeout = setTimeout(() => {
                if (!cameraInitialized) {
                    loadingCamera.innerHTML = `<div class="camera-loading">
                        <i class="fas fa-exclamation-triangle" style="color:#ffd700;"></i>
                        <span>Kamera lambat, coba tombol di bawah</span>
                    </div>`;
                    quickBtn.style.display = 'block';
                    ambilBtn.disabled = true;
                    console.log('Kamera timeout setelah 15 detik');
                }
            }, 15000);

            async function startCamera() {
                try {
                    cameraStartAttempts++;
                    
                    if (cameraStream) {
                        cameraStream.getTracks().forEach(track => track.stop());
                        cameraStream = null;
                    }
                    
                    // ===== KONFIGURASI RENDAH UNTUK STABILITAS =====
                    const constraints = {
                        video: { 
                            facingMode: 'user',
                            width: { ideal: 320, max: 480 },
                            height: { ideal: 240, max: 360 },
                            frameRate: { ideal: 15, max: 30 }
                        },
                        audio: false
                    };
                    
                    console.log('Akses kamera attempt:', cameraStartAttempts);
                    
                    cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
                    
                    video.srcObject = cameraStream;
                    
                    // Tunggu video siap (dengan race)
                    await Promise.race([
                        new Promise((resolve) => {
                            video.onloadedmetadata = () => {
                                video.play().then(resolve).catch(resolve);
                            };
                        }),
                        new Promise((resolve) => setTimeout(resolve, 5000))
                    ]);
                    
                    // Hapus timeout
                    if (cameraTimeout) {
                        clearTimeout(cameraTimeout);
                        cameraTimeout = null;
                    }
                    
                    video.style.display = 'block';
                    loadingCamera.style.display = 'none';
                    ambilBtn.disabled = false;
                    ambilBtn.style.opacity = '1';
                    cameraInitialized = true;
                    quickBtn.style.display = 'none';
                    
                    console.log('Kamera siap digunakan');
                    
                } catch (err) {
                    console.error('Gagal akses kamera:', err);
                    
                    if (cameraStartAttempts < MAX_CAMERA_ATTEMPTS) {
                        loadingCamera.innerHTML = `<div class="camera-loading">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Percobaan ${cameraStartAttempts}/${MAX_CAMERA_ATTEMPTS}...</span>
                        </div>`;
                        setTimeout(startCamera, 1000);
                    } else {
                        if (cameraTimeout) {
                            clearTimeout(cameraTimeout);
                            cameraTimeout = null;
                        }
                        loadingCamera.innerHTML = `<div class="camera-loading">
                            <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
                            <span>Kamera tidak tersedia</span>
                        </div>`;
                        quickBtn.style.display = 'block';
                        
                        // Alert user
                        alert('⚠️ Kamera tidak dapat diakses.\n\n' +
                              'Pastikan:\n' +
                              '1. Izin kamera diberikan\n' +
                              '2. Tidak ada aplikasi lain menggunakan kamera\n' +
                              '3. Browser didukung (Chrome/Edge/Safari)\n\n' +
                              'Klik "Coba Aktifkan Kamera Lagi" untuk mencoba ulang.');
                    }
                }
            }

            // Mulai kamera
            startCamera();

            // ===== TOMBOL AMBIL FOTO =====
            ambilBtn.onclick = function() {
                if (!cameraStream || !video.videoWidth) {
                    alert('Kamera belum siap, tunggu sebentar...');
                    return;
                }
                
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = 320;
                    canvas.height = 240;
                    
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, 320, 240);
                    
                    const imgData = canvas.toDataURL('image/jpeg', 0.6);
                    
                    fotoResult.src = imgData;
                    fotoResult.style.display = 'block';
                    video.style.display = 'none';
                    
                    photoData.value = imgData;
                    
                    ambilBtn.style.display = 'none';
                    ulangBtn.style.display = 'flex';
                    
                    updateSelfieStatus();
                    
                    // Matikan kamera untuk hemat resource
                    stopCamera();
                    
                    // Simpan ke cache
                    saveToCache({
                        first_name: document.getElementById('first_name').value,
                        last_name: document.getElementById('last_name').value,
                        action: document.querySelector('input[name="action"]:checked')?.value,
                        photo_data: imgData,
                        lat: document.getElementById('lat').value,
                        lng: document.getElementById('lng').value,
                        accuracy: document.getElementById('accuracy').value
                    });
                    
                } catch (err) {
                    console.error('Gagal mengambil foto:', err);
                    alert('Gagal mengambil foto: ' + err.message);
                }
            };

            // ===== TOMBOL ULANG =====
            ulangBtn.onclick = function() {
                fotoResult.style.display = 'none';
                fotoResult.src = '#';
                photoData.value = '';
                
                ambilBtn.style.display = 'flex';
                ulangBtn.style.display = 'none';
                
                updateSelfieStatus();
                
                cameraStartAttempts = 0;
                startCamera();
            };
        }

        function quickStartCamera() {
            cameraStartAttempts = 0;
            cameraInitialized = false;
            if (cameraTimeout) {
                clearTimeout(cameraTimeout);
                cameraTimeout = null;
            }
            initCamera();
        }

        // ========== FUNGSI JAM ==========
        function initJam() {
            if (formDisabled) return;
            
            const waktuInput = document.getElementById('waktuInput');
            
            function updateJam() {
                const now = new Date();
                waktuInput.value = now.toTimeString().slice(0,5);
            }
            
            updateJam();
            if (jamTimer) clearInterval(jamTimer);
            jamTimer = setInterval(updateJam, 1000);
        }

        // ========== SISTEM CACHE ==========
        const CACHE_KEY = 'absensi_form_data';
        const OFFLINE_QUEUE_KEY = 'offline_absensi_queue';
        
        function saveToCache(data) {
            try {
                const cacheData = {
                    ...data,
                    timestamp: new Date().getTime()
                };
                localStorage.setItem(CACHE_KEY, JSON.stringify(cacheData));
                showCacheInfo('📱 Data tersimpan offline');
            } catch (e) {}
        }

        function loadFromCache() {
            try {
                const cached = localStorage.getItem(CACHE_KEY);
                if (cached) return JSON.parse(cached);
            } catch (e) {}
            return null;
        }

        function clearCache() {
            localStorage.removeItem(CACHE_KEY);
            document.getElementById('cacheInfo').style.display = 'none';
        }

        function showCacheInfo(message) {
            const info = document.getElementById('cacheInfo');
            const nameSpan = document.getElementById('cacheName');
            if (info && nameSpan) {
                nameSpan.innerText = message;
                info.style.display = 'inline-block';
                setTimeout(() => info.style.display = 'none', 3000);
            }
        }

        function clearCacheAndReload() {
            clearCache();
            window.location.href = window.location.pathname;
        }

        // ========== OFFLINE SYNC FUNCTIONS ==========
        async function syncOfflineData() {
            if (!dbReady || !navigator.onLine) return;
            
            try {
                const pendingData = await offlineDB.getPendingData();
                if (pendingData.length === 0) {
                    updatePendingCount(0);
                    return;
                }
                
                updatePendingCount(pendingData.length);
                showCacheInfo(`🔄 Mengirim ${pendingData.length} data...`);
                
                // Kirim ke server melalui AJAX
                for (const data of pendingData) {
                    try {
                        const formData = new FormData();
                        formData.append('first_name', data.first_name);
                        formData.append('last_name', data.last_name);
                        formData.append('datetime', data.datetime);
                        formData.append('action', data.action);
                        formData.append('latitude', data.latitude);
                        formData.append('longitude', data.longitude);
                        formData.append('accuracy', data.accuracy);
                        formData.append('photo_data', data.photo);
                        formData.append('offline_sync', '1');
                        
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        });
                        
                        if (response.ok) {
                            await offlineDB.markAsSynced(data.id);
                            console.log('Data terkirim:', data.id);
                        }
                    } catch (err) {
                        console.error('Gagal kirim data:', err);
                    }
                }
                
                // Cek lagi data pending
                const remaining = await offlineDB.getPendingData();
                updatePendingCount(remaining.length);
                
                if (remaining.length === 0) {
                    showCacheInfo('✅ Semua data berhasil disinkronkan!');
                } else {
                    showCacheInfo(`⚠️ ${remaining.length} data gagal sync, akan dicoba ulang`);
                }
                
            } catch (error) {
                console.error('Sync error:', error);
            }
        }

        function updatePendingCount(count) {
            const el = document.getElementById('pendingCount');
            const text = document.getElementById('pendingText');
            if (el && text) {
                if (count > 0) {
                    el.style.display = 'inline-block';
                    text.innerText = `${count} data pending sync`;
                } else {
                    el.style.display = 'none';
                }
            }
        }

        async function checkPendingData() {
            if (!dbReady) return;
            try {
                const pendingData = await offlineDB.getPendingData();
                updatePendingCount(pendingData.length);
                
                if (pendingData.length > 0 && navigator.onLine) {
                    console.log('Ada data pending, sync otomatis...');
                    setTimeout(syncOfflineData, 1000);
                }
            } catch (error) {
                console.error('Gagal cek pending:', error);
            }
        }

        // ========== UPDATE OFFLINE INDICATOR ==========
        function updateOfflineIndicator() {
            const indicator = document.getElementById('offlineIndicator');
            if (!navigator.onLine) {
                indicator.style.display = 'block';
            } else {
                indicator.style.display = 'none';
            }
        }

        // ========== VALIDASI FORM ==========
        function initFormValidation() {
            const form = document.getElementById('formAbsen');
            if (!form || formDisabled) return;
            
            form.addEventListener('submit', async (e) => {
                // CEK SELFIE
                const photoData = document.getElementById('photoData').value;
                const fotoResult = document.getElementById('fotoResult');
                const cameraBox = document.getElementById('cameraBox');
                
                if (!photoData || photoData === '' || fotoResult.style.display !== 'block') {
                    e.preventDefault();
                    alert('❌ WAJIB ambil foto selfie terlebih dahulu!');
                    cameraBox.classList.add('warning');
                    cameraBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    updateSelfieStatus();
                    
                    const submitBtn = document.getElementById('submitBtn');
                    submitBtn.classList.add('selfie-missing');
                    setTimeout(() => {
                        submitBtn.classList.remove('selfie-missing');
                    }, 500);
                    return false;
                }
                
                // CEK RADIO
                const selectedAction = document.querySelector('input[name="action"]:checked');
                if (!selectedAction) {
                    e.preventDefault();
                    alert('Pilih Clock In atau Clock Out!');
                    return;
                }
                
                const now = new Date();
                const hourDecimal = now.getHours() + now.getMinutes() / 60;
                const selectedValue = selectedAction.value;
                
                if (selectedValue === 'Login' && (hourDecimal < 5.0 || hourDecimal > 12.0)) {
                    e.preventDefault();
                    alert('Clock In hanya dapat dilakukan antara jam 05:00 - 12:00!');
                    return;
                }
                
                if (selectedValue === 'Logout' && hourDecimal < 5.0) {
                    e.preventDefault();
                    alert('Clock Out dapat dilakukan setelah jam 05:00!');
                    return;
                }
                
                // CEK NAMA
                if (!document.querySelector('input[name="first_name"]')?.value.trim()) {
                    e.preventDefault();
                    alert('Isi nama!');
                    return;
                }
                
                if (!document.querySelector('input[name="last_name"]')?.value.trim()) {
                    e.preventDefault();
                    alert('Isi departemen!');
                    return;
                }
                
                // ===== OFFLINE MODE =====
                if (!navigator.onLine || !dbReady) {
                    e.preventDefault();
                    
                    const data = {
                        first_name: document.getElementById('first_name').value,
                        last_name: document.getElementById('last_name').value,
                        datetime: document.getElementById('tglInput').value + ' ' + document.getElementById('waktuInput').value,
                        action: selectedValue,
                        latitude: document.getElementById('lat').value,
                        longitude: document.getElementById('lng').value,
                        accuracy: document.getElementById('accuracy').value,
                        photo: photoData
                    };
                    
                    try {
                        if (dbReady) {
                            await offlineDB.saveData(data);
                            showCacheInfo('📱 Data disimpan offline!');
                            
                            // Tampilkan success modal
                            const modalPhoto = document.getElementById('modalPhoto');
                            const modalNama = document.getElementById('modalNama');
                            const modalStatus = document.getElementById('modalStatus');
                            
                            modalPhoto.src = photoData;
                            modalNama.innerText = data.first_name + ' ' + data.last_name;
                            modalStatus.innerText = data.action === 'Login' ? 'Clock In' : 'Clock Out';
                            
                            if (data.datetime) {
                                const d = new Date(data.datetime);
                                document.getElementById('modalWaktu').innerText = 
                                    d.toLocaleDateString('id-ID') + ' ' + 
                                    d.toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
                            }
                            
                            if (data.latitude && data.longitude) {
                                document.getElementById('modalLokasi').innerText = 
                                    `${data.latitude}, ${data.longitude}`;
                            }
                            
                            if (data.accuracy) {
                                document.getElementById('modalAkurasi').innerText = 
                                    `${data.accuracy} meter`;
                            }
                            
                            document.getElementById('successModal').style.display = 'flex';
                            
                            // Reset form
                            document.getElementById('photoData').value = '';
                            document.getElementById('fotoResult').style.display = 'none';
                            document.getElementById('fotoResult').src = '#';
                            document.getElementById('ambilFotoBtn').style.display = 'flex';
                            document.getElementById('ulangFotoBtn').style.display = 'none';
                            updateSelfieStatus();
                            
                            // Update pending count
                            const pending = await offlineDB.getPendingData();
                            updatePendingCount(pending.length);
                            
                            clearCache();
                            
                            // Coba sync jika online
                            if (navigator.onLine) {
                                setTimeout(syncOfflineData, 2000);
                            }
                        } else {
                            alert('⚠️ Database offline belum siap. Tunggu sebentar atau refresh.');
                        }
                    } catch (error) {
                        console.error('Gagal simpan offline:', error);
                        alert('❌ Gagal menyimpan data. Coba lagi.');
                    }
                    
                    return false;
                }
            });
        }

        // ========== TAMPILKAN MODAL SUKSES ==========
        function showSuccessModal() {
            if (!lastSubmittedData) return;
            
            const modal = document.getElementById('successModal');
            const modalPhoto = document.getElementById('modalPhoto');
            const modalNama = document.getElementById('modalNama');
            const modalStatus = document.getElementById('modalStatus');
            const modalWaktu = document.getElementById('modalWaktu');
            const modalLokasi = document.getElementById('modalLokasi');
            const modalAkurasi = document.getElementById('modalAkurasi');
            
            modalNama.innerText = (lastSubmittedData.first_name || '') + ' ' + (lastSubmittedData.last_name || '');
            modalStatus.innerText = lastSubmittedData.action === 'Login' ? 'Clock In' : 'Clock Out';
            
            if (lastSubmittedData.datetime) {
                const d = new Date(lastSubmittedData.datetime);
                modalWaktu.innerText = d.toLocaleDateString('id-ID') + ' ' + 
                                      d.toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
            }
            
            if (lastSubmittedData.latitude && lastSubmittedData.longitude) {
                modalLokasi.innerText = `${lastSubmittedData.latitude}, ${lastSubmittedData.longitude}`;
            }
            
            if (lastSubmittedData.accuracy) {
                modalAkurasi.innerText = `${lastSubmittedData.accuracy} meter`;
            }
            
            if (lastSubmittedData.photo_data) {
                modalPhoto.src = lastSubmittedData.photo_data;
            }
            
            modal.style.display = 'flex';
            clearCache();
        }

        // ========== PWA INSTALL ==========
        let deferredPrompt;
        
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            
            const installPrompt = document.getElementById('installPrompt');
            if (installPrompt) {
                installPrompt.style.display = 'flex';
            }
            
            const manualBtn = document.getElementById('manualInstallBtn');
            if (manualBtn) {
                manualBtn.style.display = 'none';
            }
        });

        document.getElementById('installBtn')?.addEventListener('click', async () => {
            if (!deferredPrompt) {
                alert('Aplikasi sudah terinstall atau tidak didukung');
                return;
            }
            
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            
            if (outcome === 'accepted') {
                document.getElementById('installPrompt').style.display = 'none';
            }
            deferredPrompt = null;
        });

        function manualInstall() {
            alert('Untuk menginstall aplikasi:\n\n' +
                  '1. Klik menu browser (titik tiga / ⋮)\n' +
                  '2. Pilih "Install App" atau "Add to Home Screen"\n' +
                  '3. Ikuti petunjuk selanjutnya\n\n' +
                  '💡 Di Safari: Klik Share (kotak panah) → "Add to Home Screen"');
        }

        window.addEventListener('appinstalled', () => {
            document.getElementById('installPrompt').style.display = 'none';
            document.getElementById('manualInstallBtn').style.display = 'none';
            showCacheInfo('✅ Aplikasi berhasil diinstall!');
        });

        if (window.matchMedia('(display-mode: standalone)').matches || 
            window.navigator.standalone === true) {
            document.getElementById('installPrompt').style.display = 'none';
            document.getElementById('manualInstallBtn').style.display = 'none';
        }

        setTimeout(() => {
            const installPrompt = document.getElementById('installPrompt');
            const manualBtn = document.getElementById('manualInstallBtn');
            
            if (installPrompt && installPrompt.style.display !== 'flex') {
                const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                                    window.navigator.standalone === true;
                
                if (!isStandalone && manualBtn) {
                    manualBtn.style.display = 'flex';
                }
            }
        }, 3000);

        // ========== EVENT ONLINE/OFFLINE ==========
        window.addEventListener('online', () => {
            updateOfflineIndicator();
            showCacheInfo('🔄 Kembali online, sync data...');
            setTimeout(syncOfflineData, 1000);
        });

        window.addEventListener('offline', () => {
            updateOfflineIndicator();
            showCacheInfo('📴 Mode offline - data disimpan lokal');
        });

        // ========== RESTORE DATA DARI CACHE ==========
        function restoreFromCache() {
            if (formDisabled) return;
            
            const cached = loadFromCache();
            if (cached && (new Date().getTime() - cached.timestamp < 3600000)) {
                document.getElementById('first_name').value = cached.first_name || '';
                document.getElementById('last_name').value = cached.last_name || '';
                
                if (cached.action) {
                    const radio = document.querySelector(`input[name="action"][value="${cached.action}"]`);
                    if (radio) radio.checked = true;
                }
                
                if (cached.photo_data) {
                    document.getElementById('fotoResult').src = cached.photo_data;
                    document.getElementById('fotoResult').style.display = 'block';
                    document.getElementById('video').style.display = 'none';
                    document.getElementById('photoData').value = cached.photo_data;
                    
                    document.getElementById('ambilFotoBtn').style.display = 'none';
                    document.getElementById('ulangFotoBtn').style.display = 'flex';
                    
                    updateSelfieStatus();
                    stopCamera();
                }
                
                if (cached.lat && cached.lng) {
                    document.getElementById('lat').value = cached.lat;
                    document.getElementById('lng').value = cached.lng;
                    document.getElementById('accuracy').value = cached.accuracy || '';
                    
                    const akurasiSpan = document.getElementById('akurasiText');
                    if (cached.accuracy) {
                        akurasiSpan.innerHTML = `📍 ${cached.lat}, ${cached.lng} (tersimpan, ±${cached.accuracy}m)`;
                    }
                }
                
                showCacheInfo('Data di-restore dari cache');
            }
        }

        // ========== INIT ==========
        document.addEventListener('DOMContentLoaded', async function() {
            // Inisialisasi offline DB
            try {
                await offlineDB.init();
                console.log('Offline Database ready');
                
                // Cek data pending
                setTimeout(checkPendingData, 1000);
            } catch (error) {
                console.error('Gagal init offline DB:', error);
            }
            
            // Update offline indicator
            updateOfflineIndicator();
            
            // Tampilkan success modal
            showSuccessModal();
            
            <?php if ($login_error && isset($_POST['login_submit'])): ?>
            openLoginModal();
            <?php endif; ?>
            
            // Inisialisasi form validation
            initFormValidation();
        });

        document.getElementById('modalCloseBtn')?.addEventListener('click', () => {
            document.getElementById('successModal').style.display = 'none';
        });

        window.addEventListener('beforeunload', () => {
            if (jamTimer) {
                clearInterval(jamTimer);
            }
            if (timeCheckTimer) {
                clearInterval(timeCheckTimer);
            }
            if (cameraTimeout) {
                clearTimeout(cameraTimeout);
            }
            stopCamera();
        });

        // ========== EXPOSE FUNGSI UNTUK DEBUG ==========
        window.debug = {
            syncOfflineData,
            checkPendingData,
            offlineDB,
            clearCache,
            resetApp: function() {
                if ('caches' in window) {
                    caches.keys().then(keys => {
                        keys.forEach(key => caches.delete(key));
                    });
                }
                localStorage.clear();
                indexedDB.deleteDatabase('AbsensiDB');
                setTimeout(() => window.location.reload(), 1000);
            }
        };
    </script>
</body>
</html>