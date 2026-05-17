<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unsubscribe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h3>Unsubscribe</h3>
                        <p class="text-muted">
                            We're sorry to see you go. Confirm your unsubscribe and we'll stop sending you
                            @if ($list)
                                emails from <strong>{{ $list->name }}</strong>.
                            @else
                                marketing emails entirely.
                            @endif
                        </p>
                        <p><small class="text-muted">{{ $subscriber->email }}</small></p>

                        <form method="POST" action="{{ url()->current() }}">
                            @csrf
                            <button class="btn btn-danger w-100">Yes, unsubscribe me</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
