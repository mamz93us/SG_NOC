<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unsubscribed</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body p-4 text-center">
                        <h3 class="text-success">You're unsubscribed</h3>
                        <p class="text-muted">
                            We won't send any more
                            @if ($list)
                                emails from <strong>{{ $list->name }}</strong>
                            @else
                                marketing emails
                            @endif
                            to <strong>{{ $subscriber->email }}</strong>.
                        </p>
                        <p><small>If this was a mistake, contact the sender to be re-added.</small></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
