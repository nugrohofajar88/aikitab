@extends('layouts.app')

@section('content')
    <style>
        @media print {
            @page { margin: 10mm; }
            body { font-size: 12px; }
        }
    </style>

    <div x-data="bookViewer({
        statusUrl: '{{ route('books.status', $book) }}',
        initialStatus: '{{ $book->status }}',
        initialProgress: {{ $book->progressPercentage() }},
        processedPages: {{ $book->pages->filter(fn ($p) => $p->paragraphs->isNotEmpty())->pluck('page_number')->values()->toJson() }},
        activeTab: '{{ $book->paragraphs()->exists() ? 'hasil' : 'preview' }}',
    })" x-init="init()">

        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-neutral-900">{{ $book->title }}</h1>
                @if ($book->author)
                    <p class="text-sm text-neutral-500">{{ $book->author }}</p>
                @endif
                @if ($book->published_at)
                    <p class="mt-1 text-xs text-emerald-600">
                        Dipublikasikan {{ $book->published_at->diffForHumans() }}
                        @if (config('services.hosted.url'))
                            &middot; <a href="{{ rtrim(config('services.hosted.url'), '/') }}/books/by-source/{{ $book->id }}" target="_blank" class="underline hover:text-emerald-700">lihat di situs publik</a>
                        @endif
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-3 print:hidden">
                @if ($book->status === 'completed')
                    <form method="POST" action="{{ route('books.publish', $book) }}"
                        onsubmit="const btn = this.querySelector('button'); btn.disabled = true; btn.textContent = 'Mempublikasikan...';">
                        @csrf
                        <button type="submit"
                            class="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700 disabled:cursor-not-allowed disabled:bg-sky-400">
                            {{ $book->published_at ? 'Publikasikan Ulang' : 'Publikasikan' }}
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('books.destroy', $book) }}"
                    onsubmit="return confirm('Hapus kitab ini beserta seluruh datanya?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs text-red-500 hover:text-red-700">Hapus kitab</button>
                </form>
            </div>
        </div>

        @if ($errors->has('publish'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 print:hidden">
                {{ $errors->first('publish') }}
            </div>
        @endif

        {{-- Busy banner: preparing preview or processing paragraphs --}}
        <template x-if="status === 'uploaded' || status === 'extracting' || status === 'processing'">
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 print:hidden">
                <p class="text-sm font-medium text-amber-800" x-text="statusLabel()"></p>
                <template x-if="status === 'processing'">
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-amber-100">
                        <div class="h-full rounded-full bg-amber-500 transition-all" :style="`width: ${progress}%`"></div>
                    </div>
                </template>
                <p class="mt-1 text-xs text-amber-600">Halaman ini akan otomatis diperbarui...</p>
                <template x-if="status === 'processing'">
                    <p class="mt-1 text-xs text-amber-500">
                        Halaman dengan tulisan padat bisa butuh 1-5 menit untuk dibaca AI, tergantung jumlah kata. Progress bar bisa terlihat diam sebentar sebelum melompat — ini normal, tidak perlu di-refresh manual.
                    </p>
                </template>
            </div>
        </template>

        <template x-if="status === 'failed'">
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 print:hidden">
                <p class="text-sm font-medium text-red-800">Gagal memproses kitab</p>
                <p class="mt-1 text-xs text-red-600">{{ $book->error_message }}</p>
            </div>
        </template>

        {{-- Step 1: preview PDF + choose page range, available once pages are parsed --}}
        @if (in_array($book->status, ['ready', 'processing', 'completed']))
            @if ($book->paragraphs()->exists())
                <div class="mb-6 flex gap-2 print:hidden">
                    <button type="button" @click="activeTab = 'preview'"
                        :class="activeTab === 'preview' ? 'bg-emerald-600 text-white' : 'bg-white text-neutral-600 border border-neutral-300'"
                        class="rounded-full px-4 py-1.5 text-sm font-medium">Preview PDF</button>
                    <button type="button" @click="activeTab = 'hasil'"
                        :class="activeTab === 'hasil' ? 'bg-emerald-600 text-white' : 'bg-white text-neutral-600 border border-neutral-300'"
                        class="rounded-full px-4 py-1.5 text-sm font-medium">Hasil Proses</button>
                </div>
            @endif

            <div x-show="activeTab === 'preview'" x-cloak>
            <div class="mb-8 rounded-xl border border-neutral-200 bg-white p-5 print:hidden">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold">Preview PDF</h2>
                    <span class="text-xs text-neutral-400">{{ $book->total_pages }} halaman</span>
                </div>

                <iframe src="{{ route('books.file', $book) }}" class="h-[85vh] min-h-[700px] w-full rounded-lg border border-neutral-200"></iframe>

                @if ($book->status !== 'processing')
                    <form method="POST" action="{{ route('books.process', $book) }}" class="mt-4 flex flex-wrap items-end gap-3"
                        onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn.dataset.submitted) { return false; } btn.dataset.submitted = '1'; btn.disabled = true; btn.textContent = 'Memproses...';">
                        @csrf
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-700">Dari halaman</label>
                            <input id="from_page_input" type="number" name="from_page" min="1" max="{{ $book->total_pages }}"
                                value="{{ old('from_page', 1) }}" required
                                class="w-24 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-700">Sampai halaman</label>
                            <input id="to_page_input" type="number" name="to_page" min="1" max="{{ $book->total_pages }}"
                                value="{{ old('to_page', $book->total_pages) }}" required
                                class="w-24 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm">
                        </div>
                        <label class="mb-1.5 flex items-center gap-1.5 text-xs text-neutral-600">
                            <input type="checkbox" name="force" value="1" {{ old('force') ? 'checked' : '' }}
                                class="rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500">
                            Paksa proses ulang (hasil lama akan diganti)
                        </label>
                        <label class="mb-1.5 flex items-center gap-1.5 text-xs text-neutral-600" title="Baca visual PDF langsung, bukan andalkan teks hasil ekstraksi. Pakai ini kalau hasilnya salah baca kata/makna berubah.">
                            <input type="checkbox" name="force_vision" value="1" {{ old('force_vision') ? 'checked' : '' }}
                                class="rounded border-neutral-300 text-sky-600 focus:ring-sky-500">
                            Paksa pakai mode vision
                        </label>
                        <button type="submit"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-emerald-400 disabled:hover:bg-emerald-400">
                            Proses Halaman Ini
                        </button>
                    </form>

                    @if ($errors->any())
                        <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            <ul class="list-inside list-disc">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </div>

            <div class="mb-8 rounded-xl border border-neutral-200 bg-white p-5 print:hidden">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold">Daftar Halaman</h2>
                    <div class="flex flex-wrap gap-3 text-[11px] text-neutral-500">
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-neutral-200"></span> Belum diproses</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span> Diproses</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span> Selesai</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-red-400"></span> Gagal</span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    @for ($n = 1; $n <= $book->total_pages; $n++)
                        @php
                            $pageForIndex = $book->pages->firstWhere('page_number', $n);
                            $pageState = $pageForIndex?->statusSummary() ?? 'empty';
                            $badgeClasses = match ($pageState) {
                                'done' => 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200',
                                'processing' => 'bg-amber-100 text-amber-700 animate-pulse',
                                'failed' => 'bg-red-100 text-red-700 hover:bg-red-200',
                                default => 'bg-neutral-100 text-neutral-500 hover:bg-neutral-200',
                            };
                        @endphp

                        @if (in_array($pageState, ['done', 'failed']))
                            <button type="button" @click="goToPage({{ $n }})"
                                :class="currentPage === {{ $n }} ? 'ring-2 ring-offset-1 ring-emerald-500' : ''"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium {{ $badgeClasses }}"
                                title="Halaman {{ $n }} — lihat hasil">
                                {{ $n }}
                            </button>
                        @elseif ($pageState === 'processing')
                            <span class="flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium {{ $badgeClasses }}"
                                title="Halaman {{ $n }} — sedang diproses">
                                {{ $n }}
                            </span>
                        @else
                            <button type="button"
                                onclick="document.getElementById('from_page_input').value={{ $n }}; document.getElementById('to_page_input').value={{ $n }}; document.getElementById('from_page_input').scrollIntoView({behavior:'smooth', block:'center'});"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium {{ $badgeClasses }}"
                                title="Halaman {{ $n }} — klik untuk isi rentang proses">
                                {{ $n }}
                            </button>
                        @endif
                    @endfor
                </div>
            </div>
            </div>
        @endif

        {{-- Step 2: rendered result of pages that have been processed --}}
        @if ($book->paragraphs()->exists())
            <div x-show="activeTab === 'hasil'" x-cloak>
            <div class="mb-6 flex flex-wrap gap-2 print:hidden">
                <button type="button" @click="mode = 'arab'"
                    :class="mode === 'arab' ? 'bg-emerald-600 text-white' : 'bg-white text-neutral-600 border border-neutral-300'"
                    class="rounded-full px-4 py-1.5 text-sm font-medium">Arab</button>
                <button type="button" @click="mode = 'perkata'"
                    :class="mode === 'perkata' ? 'bg-emerald-600 text-white' : 'bg-white text-neutral-600 border border-neutral-300'"
                    class="rounded-full px-4 py-1.5 text-sm font-medium">Arab + Terjemah Per Kata</button>
                <button type="button" @click="mode = 'kalimat'"
                    :class="mode === 'kalimat' ? 'bg-emerald-600 text-white' : 'bg-white text-neutral-600 border border-neutral-300'"
                    class="rounded-full px-4 py-1.5 text-sm font-medium">Arab + Terjemah Kalimat</button>
                <button type="button" @click="mode = 'lengkap'"
                    :class="mode === 'lengkap' ? 'bg-emerald-600 text-white' : 'bg-white text-neutral-600 border border-neutral-300'"
                    class="rounded-full px-4 py-1.5 text-sm font-medium">Lengkap</button>
            </div>

            <div class="mb-6 rounded-xl border border-neutral-200 bg-white p-5 print:hidden">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold">Daftar Halaman</h2>
                    <div class="flex flex-wrap gap-3 text-[11px] text-neutral-500">
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-neutral-200"></span> Belum diproses</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span> Diproses</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span> Selesai</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-red-400"></span> Gagal</span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    @for ($n = 1; $n <= $book->total_pages; $n++)
                        @php
                            $pageForIndex = $book->pages->firstWhere('page_number', $n);
                            $pageState = $pageForIndex?->statusSummary() ?? 'empty';
                            $badgeClasses = match ($pageState) {
                                'done' => 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200',
                                'processing' => 'bg-amber-100 text-amber-700 animate-pulse',
                                'failed' => 'bg-red-100 text-red-700 hover:bg-red-200',
                                default => 'bg-neutral-100 text-neutral-500 hover:bg-neutral-200',
                            };
                        @endphp

                        @if (in_array($pageState, ['done', 'failed']))
                            <button type="button" @click="goToPage({{ $n }})"
                                :class="currentPage === {{ $n }} ? 'ring-2 ring-offset-1 ring-emerald-500' : ''"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium {{ $badgeClasses }}"
                                title="Halaman {{ $n }} — lihat hasil">
                                {{ $n }}
                            </button>
                        @elseif ($pageState === 'processing')
                            <span class="flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium {{ $badgeClasses }}"
                                title="Halaman {{ $n }} — sedang diproses">
                                {{ $n }}
                            </span>
                        @else
                            <span class="flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium {{ $badgeClasses }}"
                                title="Halaman {{ $n }} — belum diproses">
                                {{ $n }}
                            </span>
                        @endif
                    @endfor
                </div>
            </div>

            <div class="mb-4 flex items-center justify-between gap-1 rounded-xl border border-neutral-200 bg-white px-2 py-2 sm:gap-0 sm:px-4 print:hidden">
                <button type="button" @click="prevPage()" :disabled="!hasPrev()"
                    class="whitespace-nowrap rounded-lg px-2 py-1.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100 disabled:opacity-30 disabled:hover:bg-transparent sm:px-3">
                    &larr; <span class="hidden sm:inline">Sebelumnya</span>
                </button>
                <span class="whitespace-nowrap text-sm font-medium text-neutral-700">Halaman <span x-text="currentPage"></span></span>
                <div class="flex items-center gap-1 sm:gap-2">
                    <button type="button" @click="window.print()" title="Print halaman ini"
                        class="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100 sm:px-3">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                            <path fill-rule="evenodd" d="M5 2.75C5 1.784 5.784 1 6.75 1h6.5c.966 0 1.75.784 1.75 1.75v3.552c.377.046.752.097 1.126.153A2.212 2.212 0 0 1 18 8.653v4.097A2.25 2.25 0 0 1 15.75 15h-.241l.305 3.05a.75.75 0 0 1-.746.826H4.932a.75.75 0 0 1-.746-.826L4.491 15H4.25A2.25 2.25 0 0 1 2 12.75V8.653c0-1.082.784-2.005 1.874-2.198.374-.056.75-.107 1.126-.153V2.75Zm8.5 3.19V2.75a.25.25 0 0 0-.25-.25h-6.5a.25.25 0 0 0-.25.25v3.19a41.703 41.703 0 0 1 7 0ZM6.006 15l-.29 2.9h8.568l-.29-2.9H6.006Z" clip-rule="evenodd" />
                        </svg>
                        <span class="hidden sm:inline">Print</span>
                    </button>
                    <button type="button" @click="nextPage()" :disabled="!hasNext()"
                        class="whitespace-nowrap rounded-lg px-2 py-1.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100 disabled:opacity-30 disabled:hover:bg-transparent sm:px-3">
                        <span class="hidden sm:inline">Berikutnya</span> &rarr;
                    </button>
                </div>
            </div>

            <div class="space-y-8">
                @foreach ($book->pages as $page)
                    @if ($page->paragraphs->isNotEmpty())
                        <div x-show="currentPage === {{ $page->page_number }}" id="page-{{ $page->page_number }}" class="rounded-xl border border-neutral-200 bg-white p-6 scroll-mt-4 print:rounded-none print:border-0 print:p-0">
                            <p class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-neutral-400 print:mb-2">
                                <span>Halaman {{ $page->page_number }}</span>
                                @if ($page->extraction_method === 'vision')
                                    <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] normal-case text-sky-700 print:hidden">
                                        dibaca via Gemini Vision
                                    </span>
                                @endif
                            </p>

                            <div class="space-y-6 print:space-y-2">
                                @foreach ($page->paragraphs as $paragraph)
                                    <div>
                                        @if ($paragraph->status === 'done' && $paragraph->content_json)
                                            @foreach (data_get($paragraph->content_json, 'sentences', []) as $sentence)
                                                @php
                                                    $kalimatCopyText = trim(($sentence['arabic'] ?? '')."\n\n".($sentence['translation'] ?? ''));
                                                @endphp
                                                <div class="mb-5 border-b border-neutral-100 pb-5 last:mb-0 last:border-0 last:pb-0 print:mb-2 print:border-0 print:pb-2">
                                                    <template x-if="mode === 'arab' || mode === 'kalimat'">
                                                        <p class="font-arabic text-right text-3xl leading-loose print:text-xl print:leading-snug" dir="rtl">
                                                            {{ $sentence['arabic'] ?? '' }}
                                                        </p>
                                                    </template>

                                                    <template x-if="mode === 'perkata' || mode === 'lengkap'">
                                                        <div class="flex flex-wrap items-start gap-x-4 gap-y-3 print:gap-x-2 print:gap-y-1" dir="rtl">
                                                            @foreach (data_get($sentence, 'words', []) as $word)
                                                                <div class="group relative flex flex-col items-center text-center">
                                                                    <span @class(['font-arabic text-2xl leading-loose print:text-base print:leading-snug', 'cursor-help' => !empty($word['grammar'])])>{{ $word['arabic'] ?? '' }}</span>
                                                                    <span class="mt-1 text-xs text-neutral-500" dir="ltr">{{ $word['translation'] ?? '' }}</span>

                                                                    @if (!empty($word['grammar']))
                                                                        <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 hidden w-52 -translate-x-1/2 rounded-lg bg-neutral-800 px-3 py-2 text-xs leading-relaxed text-white group-hover:block" dir="rtl">
                                                                            {{ $word['grammar'] }}
                                                                            <span class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-neutral-800"></span>
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </template>

                                                    <template x-if="mode === 'kalimat' || mode === 'lengkap'">
                                                        <div>
                                                            <p class="mt-3 text-sm italic text-neutral-600 print:mt-1 print:text-xs">
                                                                {{ $sentence['translation'] ?? '' }}
                                                            </p>
                                                            <div x-data="{ copied: false }" class="mt-1 flex justify-end print:hidden" dir="ltr">
                                                                <button type="button" title="Salin"
                                                                    @click="navigator.clipboard.writeText(@js($kalimatCopyText)); copied = true; setTimeout(() => copied = false, 1500)"
                                                                    class="rounded-md p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600">
                                                                    @include('books.partials.copy-icon')
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            @endforeach
                                        @elseif ($paragraph->status === 'failed')
                                            <div class="rounded-lg border border-red-200 bg-red-50 p-3">
                                                <p class="font-arabic text-right text-2xl leading-loose text-neutral-700" dir="rtl">
                                                    {{ $paragraph->raw_text }}
                                                </p>
                                                <p class="mt-2 text-xs text-red-600">
                                                    Gagal diproses AI: {{ $paragraph->error_message }}
                                                </p>
                                            </div>
                                        @else
                                            <div class="animate-pulse">
                                                <p class="font-arabic text-right text-2xl leading-loose text-neutral-400" dir="rtl">
                                                    {{ $paragraph->raw_text }}
                                                </p>
                                                <p class="mt-1 text-xs text-neutral-400">Sedang diproses AI...</p>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    function bookViewer({ statusUrl, initialStatus, initialProgress, processedPages, activeTab }) {
        return {
            mode: 'lengkap',
            activeTab: activeTab ?? 'preview',
            status: initialStatus,
            progress: initialProgress,
            poller: null,
            processedPages: processedPages ?? [],
            currentPage: (processedPages && processedPages.length) ? processedPages[0] : null,
            init() {
                if (['uploaded', 'extracting', 'processing'].includes(this.status)) {
                    this.poller = setInterval(() => this.poll(), 3000);
                }
            },
            goToPage(n) {
                this.currentPage = n;
                this.$nextTick(() => {
                    document.getElementById('page-' + n)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            },
            hasPrev() {
                return this.processedPages.indexOf(this.currentPage) > 0;
            },
            hasNext() {
                return this.processedPages.indexOf(this.currentPage) < this.processedPages.length - 1;
            },
            prevPage() {
                const i = this.processedPages.indexOf(this.currentPage);
                if (i > 0) this.goToPage(this.processedPages[i - 1]);
            },
            nextPage() {
                const i = this.processedPages.indexOf(this.currentPage);
                if (i < this.processedPages.length - 1) this.goToPage(this.processedPages[i + 1]);
            },
            async poll() {
                try {
                    const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    this.status = data.status;
                    this.progress = data.progress;

                    if (!['uploaded', 'extracting', 'processing'].includes(data.status)) {
                        clearInterval(this.poller);
                        window.location.reload();
                    }
                } catch (e) {
                    // network hiccup, keep polling
                }
            },
            statusLabel() {
                const labels = {
                    uploaded: 'Menyiapkan preview PDF...',
                    extracting: 'Mengekstrak teks dari PDF...',
                    processing: `Memproses harakat & terjemah (${this.progress}%)...`,
                };
                return labels[this.status] ?? this.status;
            },
        };
    }
</script>
@endpush
