<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <meta name="theme-color" content="#08080A">
    <meta name="description" content="Xpost — dang va len lich bai len X tu 1.000-10.000 tai khoan.">
    <title>{{ config('app.name', 'Xpost') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-neutral-100 font-sans antialiased">
    <div id="app" class="h-full"></div>
</body>
</html>
