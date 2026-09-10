<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Restricted</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #0f1115;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            text-align: center;
            max-width: 480px;
            width: 100%;
        }

        .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon svg {
            width: 40px;
            height: 40px;
            stroke: #dc3545;
        }

        h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #ffffff;
        }

        p {
            font-size: 15px;
            line-height: 1.6;
            color: #9ca3af;
            margin-bottom: 8px;
        }

        .code {
            display: inline-block;
            margin-top: 20px;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 6px;
            font-size: 13px;
            color: #6b7280;
            letter-spacing: 0.5px;
        }

        @media (max-width: 480px) {
            h1 {
                font-size: 20px;
            }

            p {
                font-size: 14px;
            }

            .icon {
                width: 64px;
                height: 64px;
            }

            .icon svg {
                width: 32px;
                height: 32px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="icon">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
            </svg>
        </div>
        <h1>Access Restricted</h1>
        <p>We're unable to grant access to this site from your current location.</p>
        <p>If you believe this is an error, please contact our support team.</p>
        <div class="code">ERROR 403</div>
    </div>
</body>

</html>