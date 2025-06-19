<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Laravel</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:300,400,500,600,700,800,900&display=swap" rel="stylesheet"/>

    <script>
        document.querySelector('html').setAttribute('data-theme', localStorage.getItem('theme') || 'dark');
    </script>

    @vite(['resources/js/app.js', 'resources/css/app.css'])
  </head>
  <body class="bg-base-100 text-base-content">
    @include('helpers.screen-size', ['location' => 'top-center', 'margin' => 'lg'])
    <div id="app">
      <app-component></app-component>
    </div>
  </body>
</html>