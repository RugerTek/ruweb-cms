<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Login') - {{ $siteName }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @vite([app(\App\Support\AdminCapabilities::class)->liveEditor() ? 'resources/js/admin.js' : 'resources/js/admin-essential.js'])
</head>
<body class="bg-[#0a0a0a] text-gray-100 min-h-screen flex items-center justify-center login-bg font-sans antialiased">
    <div class="w-full max-w-sm px-6">
        @yield('content')
    </div>
</body>
</html>
