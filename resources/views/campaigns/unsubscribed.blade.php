<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribed — LeadPilot AI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .unsub-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            max-width: 480px;
            width: 100%;
            padding: 2.5rem;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="unsub-card">
    <div class="mb-3">
        <i class="bi bi-shield-check text-success fs-1"></i>
    </div>
    <h4 class="fw-bold text-dark mb-2">You Have Been Unsubscribed</h4>
    <p class="text-muted small mb-3">
        Your email address <strong>{{ $email }}</strong> has been added to our global do-not-contact suppression list.
    </p>
    <div class="alert alert-light border small text-muted text-start mb-4">
        <i class="bi bi-info-circle me-1"></i>
        In compliance with GDPR and international email privacy laws, our systems prevent any future marketing emails or campaign automated outreach from reaching your address.
    </div>
    <div class="text-secondary small">
        LeadPilot AI • Agency Compliance Center
    </div>
</div>

</body>
</html>
