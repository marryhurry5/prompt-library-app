<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPI Payout Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #05070a;
            --surface: #0d1117;
            --accent: #8b5cf6;
            --border: rgba(255, 255, 255, 0.08);
            --text: #f8fafc;
            --text-dim: #94a3b8;
            --success: #10b981;
        }
        body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            position: relative;
            overflow: hidden;
        }
        /* Mesh background */
        .mesh-bg {
            position: absolute;
            inset: 0;
            z-index: 0;
            background: 
                radial-gradient(at 0% 0%, rgba(139, 92, 246, 0.12) 0, transparent 50%),
                radial-gradient(at 100% 100%, rgba(59, 130, 246, 0.08) 0, transparent 50%);
            filter: blur(80px);
        }
        .pay-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(20px);
            position: relative;
            z-index: 10;
        }
        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0 0 4px;
            background: linear-gradient(135deg, #fff 40%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .subtitle {
            color: var(--text-dim);
            font-size: 0.9rem;
            margin: 0 0 24px;
        }
        .amount-box {
            background: rgba(16, 185, 129, 0.05);
            border: 1px solid rgba(16, 185, 129, 0.15);
            border-radius: 20px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .amount-label {
            font-size: 0.8rem;
            color: var(--success);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .amount {
            font-family: 'Outfit', sans-serif;
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--success);
        }
        .qr-box {
            background: #fff;
            padding: 20px;
            border-radius: 24px;
            display: inline-block;
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        .qr-box img {
            display: block;
            width: 200px;
            height: 200px;
        }
        .details {
            font-size: 0.9rem;
            color: var(--text-dim);
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 28px;
            text-align: left;
        }
        .details-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .details-row:last-child {
            margin-bottom: 0;
        }
        .details-label {
            font-weight: 500;
        }
        .details-val {
            color: var(--text);
            font-weight: 600;
            word-break: break-all;
            text-align: right;
            max-width: 60%;
        }
        .pay-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 18px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 18px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        .pay-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(139, 92, 246, 0.3);
            filter: brightness(1.1);
        }
    </style>
</head>
<body>
    <div class="mesh-bg"></div>
    <?php
    $pa = $_GET['pa'] ?? '';
    $am = $_GET['am'] ?? '0.00';
    $pn = $_GET['pn'] ?? 'Creator';
    $tn = $_GET['tn'] ?? 'Withdrawal';
    
    // Clean inputs
    $pa = trim($pa);
    $pn = trim($pn);
    $tn = trim($tn);

    // Build standard UPI deep link string
    $upi_string = "upi://pay?pa=" . rawurlencode($pa) . "&am=" . rawurlencode($am) . "&pn=" . rawurlencode($pn) . "&tn=" . rawurlencode($tn);
    
    // Create QR code from google charts / qrserver API
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data=" . urlencode($upi_string);
    ?>
    <div class="pay-card">
        <h1>RTM Creator Payout</h1>
        <p class="subtitle">Quick and secure UPI payout portal</p>
        
        <div class="amount-box">
            <div class="amount-label">Payout Amount</div>
            <div class="amount">₹<?php echo htmlspecialchars(number_format((float)$am, 2)); ?></div>
        </div>
        
        <div class="qr-box">
            <img src="<?php echo $qr_url; ?>" alt="UPI Payout QR Code">
        </div>
        
        <div class="details">
            <div class="details-row">
                <span class="details-label">UPI VPA</span>
                <span class="details-val"><?php echo htmlspecialchars($pa); ?></span>
            </div>
            <div class="details-row">
                <span class="details-label">Payee Name</span>
                <span class="details-val"><?php echo htmlspecialchars($pn); ?></span>
            </div>
            <div class="details-row">
                <span class="details-label">Ref Note</span>
                <span class="details-val"><?php echo htmlspecialchars($tn); ?></span>
            </div>
        </div>
        
        <a href="<?php echo $upi_string; ?>" class="pay-btn">
            <i class="fa-solid fa-mobile-screen-button"></i> Open UPI App
        </a>
    </div>
    
    <script>
        // Automatic redirection to UPI handler if visiting on a mobile device
        if (/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            window.location.href = "<?php echo $upi_string; ?>";
        }
    </script>
</body>
</html>
