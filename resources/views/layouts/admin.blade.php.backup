<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f8f9fa;
        }
        .nav-link.active {
            font-weight: bold;
        }
    </style>
</head>

<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="/admin">Admin Panel</a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">

                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/branches*') ? 'active' : '' }}" href="/admin/branches">
                            Branches
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/contacts*') ? 'active' : '' }}" href="/admin/contacts">
                            Contacts
                        </a>
                    </li>
                </ul>

                <form action="/logout" method="POST" class="d-flex">
                    @csrf
                    <button class="btn btn-outline-light btn-sm">Logout</button>
                </form>

            </div>
        </div>
    </nav>

    <!-- PAGE CONTENT -->
    <div class="container mt-4 mb-5">
        @yield('content')
