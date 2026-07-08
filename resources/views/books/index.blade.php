@extends('layouts.app')

@section('content')
    <div class="grid gap-8 md:grid-cols-3">
        <div class="md:col-span-1">
            <div class="rounded-xl border border-neutral-200 bg-white p-5">
                <h2 class="mb-4 text-base font-semibold">Upload Kitab (PDF)</h2>

                @if ($errors->any())
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        <ul class="list-inside list-disc">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('books.store') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700">Judul Kitab</label>
                        <input type="text" name="title" value="{{ old('title') }}" required
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700">Penulis (opsional)</label>
                        <input type="text" name="author" value="{{ old('author') }}"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700">File PDF</label>
                        <input type="file" name="pdf" accept="application/pdf" required
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-emerald-50 file:px-3 file:py-1.5 file:text-emerald-700">
                        <p class="mt-1 text-xs text-neutral-400">Maks. 50MB. PDF harus punya teks (bukan hasil scan/gambar).</p>
                    </div>
                    <button type="submit"
                        class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Upload &amp; Proses
                    </button>
                </form>
            </div>
        </div>

        <div class="md:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-semibold">Daftar Kitab</h2>
                <a href="{{ route('sync.requests.index') }}"
                    class="rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-xs font-medium text-neutral-600 hover:border-emerald-300 hover:text-emerald-700">
                    Cek Permintaan Baru
                </a>
            </div>

            @if ($books->isEmpty())
                <p class="text-sm text-neutral-400">Belum ada kitab yang diupload.</p>
            @else
                <div class="space-y-3">
                    @foreach ($books as $book)
                        <a href="{{ route('books.show', $book) }}"
                            class="block rounded-xl border border-neutral-200 bg-white p-4 hover:border-emerald-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-medium text-neutral-900">{{ $book->title }}</h3>
                                    @if ($book->author)
                                        <p class="text-sm text-neutral-500">{{ $book->author }}</p>
                                    @endif
                                </div>
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-neutral-100 text-neutral-600' => $book->status === 'uploaded',
                                    'bg-amber-100 text-amber-700' => in_array($book->status, ['extracting', 'processing']),
                                    'bg-emerald-100 text-emerald-700' => $book->status === 'completed',
                                    'bg-red-100 text-red-700' => $book->status === 'failed',
                                ])>
                                    {{ $book->status }}
                                </span>
                            </div>
                            @if ($book->total_paragraphs > 0)
                                <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-neutral-100">
                                    <div class="h-full rounded-full bg-emerald-500"
                                        style="width: {{ $book->progressPercentage() }}%"></div>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
