<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'KitabAI' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Scheherazade+New:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .font-arabic {
            font-family: 'Amiri', 'Scheherazade New', 'Traditional Arabic', serif;
        }
    </style>
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 antialiased">
    <nav class="border-b border-neutral-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
            <a href="{{ route('books.index') }}" class="text-lg font-bold tracking-tight text-emerald-700">
                Kitab<span class="text-neutral-800">AI</span>
            </a>
            <div class="flex items-center gap-3">
                @php
                    [$geminiUsed, $geminiCapacity] = \App\Services\GeminiService::todayUsageSummary();
                    $geminiModels = \App\Services\GeminiService::modelPriority();
                @endphp
                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $geminiUsed >= $geminiCapacity ? 'bg-red-100 text-red-700' : 'bg-neutral-100 text-neutral-500' }}"
                    title="Perkiraan jumlah request ke Gemini hari ini (reset tiap tengah malam), gabungan {{ count(\App\Services\GeminiService::keyPool()) }} API key × model: {{ implode(', ', $geminiModels) }}">
                    Gemini hari ini: {{ $geminiUsed }}/{{ $geminiCapacity }}
                </span>
                <span class="text-xs text-neutral-400">MVP v1.0</span>
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-5xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
