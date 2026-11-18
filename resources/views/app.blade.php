<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0B0D10">
    <meta name="color-scheme" content="dark light">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FUEL">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icon-192.png" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title inertia>{{ config('app.name') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-dvh antialiased">
    @inertia
</body>
</html>
