@extends('layouts.app')
@section('title', __('Knowledge'))
@section('content')
<h1>{{ __('Knowledge') }}</h1>
<p class="muted">{{ __('Your assistant answers only from what is here. Add your website, files and frequent questions.') }}</p>

<div class="grid">
    <div class="card" style="margin:0">
        <h2>{{ __('Website') }}</h2>
        <form method="post" action="{{ route('portal.knowledge.website') }}">
            @csrf
            <label for="url">{{ __('Website or sitemap address') }}</label>
            <input type="url" id="url" name="url" placeholder="https://www.example.com" required>
            <p class="small muted">{{ __('We read the pages of this site (same domain only) and read it again every week.') }}</p>
            <button class="btn">{{ __('Read website') }}</button>
        </form>
    </div>
    <div class="card" style="margin:0">
        <h2>{{ __('File') }}</h2>
        <form method="post" action="{{ route('portal.knowledge.file') }}" enctype="multipart/form-data">
            @csrf
            <label for="file">{{ __('PDF, TXT, MD or CSV, up to 10 MB') }}</label>
            <input type="file" id="file" name="file" accept=".pdf,.txt,.md,.csv" required>
            <p class="small muted">{{ __('Only the text is kept. Scanned PDFs have no text.') }}</p>
            <button class="btn">{{ __('Upload') }}</button>
        </form>
    </div>
    <div class="card" style="margin:0">
        <h2>{{ __('Question and answer') }}</h2>
        <form method="post" action="{{ route('portal.knowledge.faq') }}">
            @csrf
            <label for="question">{{ __('Question') }}</label>
            <input type="text" id="question" name="question" required maxlength="250">
            <label for="answer">{{ __('Answer') }}</label>
            <textarea id="answer" name="answer" required maxlength="5000"></textarea>
            <button class="btn">{{ __('Add') }}</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>{{ __('Sources') }}</h2>
    <table>
        <thead><tr><th>{{ __('Source') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Passages') }}</th><th>{{ __('Updated') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($sources as $s)
            <tr>
                <td>
                    {{ \Illuminate\Support\Str::limit($s->title, 70) }}
                    @if ($s->url)<div class="small"><a href="{{ $s->url }}" target="_blank" rel="noopener" class="copy">{{ \Illuminate\Support\Str::limit($s->url, 60) }}</a></div>@endif
                    @if ($s->error)<div class="small" style="color:var(--bad)">{{ $s->error }}</div>@endif
                    @if ($s->type === 'website' && ($read[$s->id] ?? collect())->isNotEmpty())
                        <details class="read-list">
                            <summary class="small">{{ __('Pages and files read (:n)', ['n' => $read[$s->id]->count()]) }}</summary>
                            <ul>
                                @foreach ($read[$s->id] as $item)
                                    <li class="small">
                                        <span class="badge">{{ str_ends_with(strtolower((string) parse_url((string) $item->url, PHP_URL_PATH)), '.pdf') ? 'PDF' : __('Page') }}</span>
                                        <a href="{{ $item->url }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($item->title ?: $item->url, 70) }}</a>
                                        <span class="muted">· {{ __(':n passages', ['n' => $item->passages]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </td>
                <td>{{ __(ucfirst($s->type)) }}</td>
                <td><span class="badge {{ $s->status }}">{{ __(ucfirst($s->status)) }}</span>@if ($s->type === 'website' && $s->pages)<div class="small muted">{{ __(':n pages', ['n' => $s->pages]) }}</div>@endif</td>
                <td>{{ $s->chunks_count }}</td>
                <td class="small muted">{{ $s->synced_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="row">
                    @if ($s->type === 'website')
                        <form method="post" action="{{ route('portal.knowledge.sync', $s) }}">@csrf<button class="btn sm light">{{ __('Read again') }}</button></form>
                    @endif
                    <form method="post" action="{{ route('portal.knowledge.destroy', $s) }}" onsubmit="return confirm(@js(__('Delete this source?')))">@csrf @method('delete')<button class="btn sm danger">{{ __('Delete') }}</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('Nothing yet. Start with your website.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if ($sources->whereIn('status', ['pending', 'syncing'])->isNotEmpty())
    <script>setTimeout(() => location.reload(), 15000);</script>
@endif
@endsection
