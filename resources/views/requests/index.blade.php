@extends('layouts.app')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-bold text-neutral-900">Permintaan Kitab dari Situs Publik</h1>
        <a href="{{ route('books.index') }}" class="text-sm text-neutral-500 hover:text-neutral-700">&larr; Kembali ke Daftar Kitab</a>
    </div>

    @if ($error)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            Gagal mengambil daftar permintaan dari situs publik: {{ $error }}
        </div>
    @endif

    @if (empty($requests))
        @if (! $error)
            <p class="text-sm text-neutral-400">Tidak ada permintaan baru saat ini.</p>
        @endif
    @else
        <div class="space-y-3">
            @foreach ($requests as $req)
                <div class="rounded-xl border border-neutral-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-neutral-900">{{ $req['title'] }}</h3>
                            @if (!empty($req['author']))
                                <p class="text-sm text-neutral-500">{{ $req['author'] }}</p>
                            @endif
                            <p class="mt-1 text-xs text-neutral-400">
                                Diminta {{ \Illuminate\Support\Carbon::parse($req['created_at'])->diffForHumans() }}
                                @if (!empty($req['requester_name']))
                                    oleh {{ $req['requester_name'] }}
                                @endif
                            </p>
                            @if (!empty($req['requester_note']))
                                <p class="mt-2 text-sm italic text-neutral-600">"{{ $req['requester_note'] }}"</p>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('sync.requests.import') }}">
                            @csrf
                            <input type="hidden" name="remote_id" value="{{ $req['id'] }}">
                            <input type="hidden" name="uuid" value="{{ $req['uuid'] }}">
                            <input type="hidden" name="title" value="{{ $req['title'] }}">
                            <input type="hidden" name="author" value="{{ $req['author'] }}">
                            <input type="hidden" name="original_filename" value="{{ $req['original_filename'] }}">
                            <button type="submit"
                                class="whitespace-nowrap rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                Impor &amp; Proses
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
