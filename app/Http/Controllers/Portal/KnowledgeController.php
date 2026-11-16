<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\SyncKnowledgeSource;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Services\Knowledge\FileText;
use App\Services\Knowledge\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class KnowledgeController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }

    private function own(Request $request, KnowledgeSource $source): KnowledgeSource
    {
        abort_unless($source->tenant_id === $this->tenant($request)->id, 404);

        return $source;
    }

    public function index(Request $request)
    {
        $sources = $this->tenant($request)->sources()->withCount('chunks')->latest()->get();

        // What each website sync read: one row per page or file, with how many passages it gave
        $read = \App\Models\KnowledgeChunk::query()
            ->whereIn('knowledge_source_id', $sources->where('type', 'website')->pluck('id'))
            ->selectRaw('knowledge_source_id, url, min(title) as title, count(*) as passages')
            ->groupBy('knowledge_source_id', 'url')
            ->orderBy('url')
            ->get()
            ->groupBy('knowledge_source_id');

        return view('portal.knowledge', ['sources' => $sources, 'read' => $read]);
    }

    public function storeWebsite(Request $request)
    {
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:2048']]);
        if (! SafeUrl::isAllowed($data['url'])) {
            throw ValidationException::withMessages(['url' => __('This address cannot be read. Use the public address of the website.')]);
        }
        $source = $this->tenant($request)->sources()->create([
            'type' => 'website',
            'title' => parse_url($data['url'], PHP_URL_HOST),
            'url' => $data['url'],
        ]);
        SyncKnowledgeSource::dispatch($source);

        return back()->with('status', __('The website is being read. This takes a few minutes.'));
    }

    public function storeFaq(Request $request)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:250'],
            'answer' => ['required', 'string', 'max:5000'],
        ]);
        $source = $this->tenant($request)->sources()->create([
            'type' => 'faq',
            'title' => $data['question'],
            'content' => $data['answer'],
        ]);
        SyncKnowledgeSource::dispatchSync($source);

        return back()->with('status', __('Saved.'));
    }

    public function storeFile(Request $request, FileText $reader)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,txt,md,csv']]);
        $file = $request->file('file');
        try {
            $text = $reader->read($file);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => __('No text could be read from this file.')]);
        }
        $source = $this->tenant($request)->sources()->create([
            'type' => 'file',
            'title' => mb_substr($file->getClientOriginalName(), 0, 250),
            'content' => $text,
        ]);
        SyncKnowledgeSource::dispatchSync($source);

        return back()->with('status', __('Saved.'));
    }

    public function sync(Request $request, KnowledgeSource $source)
    {
        $source = $this->own($request, $source);
        $source->update(['status' => 'pending']);
        SyncKnowledgeSource::dispatch($source);

        return back()->with('status', __('The source is being read again.'));
    }

    /**
     * Danger zone: deletes all of this client's knowledge (every source and passage) so it can be built again from
     * scratch. Only after the word RESET is typed. Conversations, keys and settings are kept.
     */
    public function reset(Request $request)
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if (strtoupper(trim($request->input('confirm'))) !== 'RESET') {
            throw ValidationException::withMessages(['confirm' => __('Type RESET to confirm. Nothing was deleted.')]);
        }

        $tenant = $this->tenant($request);
        [$sources, $passages] = DB::transaction(function () use ($tenant) {
            $passages = $tenant->chunks()->delete();
            $sources = $tenant->sources()->delete();

            return [$sources, $passages];
        });
        Log::notice('Knowledge reset', ['tenant' => $tenant->id, 'by' => $request->user()?->email ?? 'admin', 'sources' => $sources, 'passages' => $passages]);

        return redirect()->route('portal.knowledge')
            ->with('status', __('Knowledge reset: :s sources and :p passages deleted. The assistant now knows nothing until you add new knowledge.', ['s' => $sources, 'p' => $passages]));
    }

    public function destroy(Request $request, KnowledgeSource $source)
    {
        $this->own($request, $source)->delete();

        return back()->with('status', __('Deleted.'));
    }
}
