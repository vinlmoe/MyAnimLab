<?php

/**
 * Scan a cage QR code with the device camera to run its daily check.
 *
 * Accepts the QR codes already printed on cage cards (URLs such as
 * https://host/hc_view.php?id=P-00012) or a plain cage ID, and opens
 * cage_check.php for that cage. A manual entry field covers devices
 * without a camera.
 */

session_start();
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

// Manual entry / non-JS fallback: same parsing as the scanner below.
if (isset($_GET['code'])) {
    $code = trim($_GET['code']);
    $query = parse_url($code, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $qs);
        $code = $qs['id'] ?? $code;
    }
    header("Location: cage_check.php?" . http_build_query(['id' => $code, 'via' => 'qr']));
    exit;
}

require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Scan Cage QR | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        .container {
            max-width: 600px;
        }

        #reader {
            width: 100%;
        }
    </style>
</head>

<body>
    <div class="container mt-3 content">
        <h4><i class="fas fa-qrcode"></i> Scan a cage QR code</h4>
        <p class="text-muted">Point the camera at the QR code on the cage card. The cage's daily check opens automatically.</p>

        <div id="reader" class="mb-3"></div>
        <div id="scanError" class="alert alert-warning d-none"></div>

        <form method="GET" class="input-group mb-3">
            <input type="text" class="form-control" name="code" id="code" placeholder="Or type the cage ID (e.g. P-00012)" required>
            <button class="btn btn-primary" type="submit">Open</button>
        </form>

        <a href="daily_round.php" class="btn btn-outline-secondary w-100">Back to the daily round</a>
    </div>

    <script>
        (function() {
            const errorBox = document.getElementById('scanError');
            const showError = msg => {
                errorBox.textContent = msg;
                errorBox.classList.remove('d-none');
            };
            if (typeof Html5Qrcode === 'undefined') {
                showError('Camera scanner unavailable (library not loaded). Type the cage ID below.');
                return;
            }
            // Start the back camera straight away: one less tap during the round.
            let done = false;
            const scanner = new Html5Qrcode('reader');
            scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 250 }, text => {
                if (done) return;
                done = true;
                scanner.stop().catch(() => {});
                window.location.href = 'qr_scan.php?code=' + encodeURIComponent(text);
            }, () => {}).catch(err => {
                showError('Cannot access the camera (' + err + '). The site must be served over HTTPS and camera access allowed. You can type the cage ID below.');
            });
        })();
    </script>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
