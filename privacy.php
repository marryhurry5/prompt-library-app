<?php
// Privacy Policy Web Page for AI Prompt Hub / Prompt Marketplace
// Required URL for Google Play Console & Amazon Developer Portal Store Listings
// Hosted at: https://rtmcreator.com/bots/prompt-bot/privacy.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - AI Prompt Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: #131b2e;
            --accent: #6366f1;
            --accent-light: #818cf8;
            --text-main: #f8fafc;
            --text-secondary: #94a3b8;
            --border-color: #1e293b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            line-height: 1.7;
            padding: 40px 20px;
        }

        .container {
            max-width: 860px;
            margin: 0 auto;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 48px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
        }

        .header {
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 24px;
            margin-bottom: 36px;
        }

        .badge {
            display: inline-block;
            background: rgba(99, 102, 241, 0.15);
            color: var(--accent-light);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .effective-date {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        h2 {
            font-size: 1.35rem;
            font-weight: 600;
            margin-top: 32px;
            margin-bottom: 14px;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        p {
            color: var(--text-secondary);
            margin-bottom: 16px;
            font-size: 1rem;
        }

        ul {
            list-style: none;
            padding-left: 0;
            margin-bottom: 20px;
        }

        li {
            color: var(--text-secondary);
            position: relative;
            padding-left: 24px;
            margin-bottom: 10px;
            font-size: 0.98rem;
        }

        li::before {
            content: "•";
            color: var(--accent-light);
            font-size: 1.4rem;
            position: absolute;
            left: 6px;
            top: -2px;
        }

        .highlight-box {
            background: rgba(99, 102, 241, 0.08);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
        }

        .highlight-box p {
            margin-bottom: 0;
            color: #e2e8f0;
        }

        a {
            color: var(--accent-light);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .footer {
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.88rem;
        }

        @media (max-width: 640px) {
            body {
                padding: 16px;
            }
            .container {
                padding: 24px 20px;
            }
            h1 {
                font-size: 1.7rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="badge">Official Policy</div>
            <h1>Privacy Policy</h1>
            <div class="effective-date">Application: <strong>AI Prompt Hub</strong> (com.rtmcreator.promptlibrary) | Last Updated: September 2026</div>
        </div>

        <p>This Privacy Policy explains how <strong>AI Prompt Hub</strong> ("we", "our", or "the App") collects, uses, and discloses information when you use our mobile application and connected backend services.</p>

        <h2>1. Information We Collect</h2>
        <p>We believe in user privacy and data minimization. We do not require you to create an account or provide personal identification details to explore, search, and copy AI prompts.</p>
        <ul>
            <li><strong>Local Application Data:</strong> Bookmarks, favorites, and user preferences are stored locally on your device using encrypted native storage (SharedPreferences) and are never uploaded to our servers without your consent.</li>
            <li><strong>Technical & Diagnostic Data:</strong> Standard network request logs (such as IP address, operating system version, and general device type) are processed temporarily by our web server to deliver API responses and prevent denial-of-service attacks.</li>
        </ul>

        <h2>2. Third-Party Services & Advertising (Google AdMob)</h2>
        <p>Our application integrates Google Mobile Ads (AdMob) to display non-intrusive banner and rewarded video advertisements that allow users to unlock premium prompt resources for free.</p>
        <div class="highlight-box">
            <p><strong>Google AdMob Disclosure:</strong> Google may use advertising identifiers (such as Google Advertising ID / AAID) and device information to deliver personalized or contextual ads in accordance with Google's Advertising Policies. You can manage or reset your advertising ID in your Android device settings under <em>Settings → Google → Ads</em>.</p>
        </div>
        <p>For more details on how Google processes ad data, please review the <a href="https://policies.google.com/technologies/ads" target="_blank" rel="noopener">Google Privacy & Terms Policy</a>.</p>

        <h2>3. Permissions Requested</h2>
        <ul>
            <li><strong>INTERNET & ACCESS_NETWORK_STATE:</strong> Required to query and display prompt collections from our secure API endpoints (HTTPS).</li>
            <li><strong>POST_NOTIFICATIONS:</strong> On Android 13 (API 33) and higher, the app requests notification permission to alert you when daily trending prompts, challenges, or system updates are published. You may disable notifications at any time in your device settings.</li>
        </ul>

        <h2>4. Data Security</h2>
        <p>All communication between the application and our servers is strictly encrypted using industry-standard Transport Layer Security (TLS/HTTPS). We do not sell, rent, or trade your data to any third parties.</p>

        <h2>5. Children's Privacy (COPPA Compliance)</h2>
        <p>Our application is not targeted at children under the age of 13. We do not knowingly collect personal identifiable information from children under 13.</p>

        <h2>6. Changes to This Policy</h2>
        <p>We may update our Privacy Policy periodically to reflect app updates or regulatory changes. Any updates will be posted directly to this web page.</p>

        <h2>7. Contact Us</h2>
        <p>If you have questions, feedback, or privacy-related requests regarding this Privacy Policy, please contact our support team:</p>
        <ul>
            <li><strong>Email:</strong> <a href="mailto:support@rtmcreator.com">support@rtmcreator.com</a></li>
            <li><strong>Telegram Support:</strong> <a href="https://t.me/Prompts_library_bot" target="_blank" rel="noopener">@Prompts_library_bot</a></li>
            <li><strong>Website:</strong> <a href="https://rtmcreator.com" target="_blank" rel="noopener">rtmcreator.com</a></li>
        </ul>

        <div class="footer">
            &copy; 2026 AI Prompt Hub &bull; All Rights Reserved.
        </div>
    </div>
</body>
</html>
