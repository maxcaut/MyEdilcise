<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    public function dashboard(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:50'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $selectedMonth = $filters['month'] ?? now('Europe/Rome')->format('Y-m');
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $selectedMonth, 'Europe/Rome');
        $monthLabel = $monthStart->locale('it')->translatedFormat('F Y');
        $monthNavigation = [
            'previous' => route('dashboard', array_replace($filters, ['month' => $monthStart->subMonth()->format('Y-m')])),
            'next' => route('dashboard', array_replace($filters, ['month' => $monthStart->addMonth()->format('Y-m')])),
            'current' => route('dashboard', array_replace($filters, ['month' => now('Europe/Rome')->format('Y-m')])),
        ];
        $query = DB::table('documents')
            ->where('date', '>=', $monthStart->toDateString())
            ->where('date', '<', $monthStart->addMonth()->toDateString());
        $monthlyTotal = (clone $query)->sum('amount');
        $query->select('id', 'title', 'supplier', 'date', 'category', 'amount');
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orWhereRaw('LOWER(supplier) LIKE ?', ['%'.mb_strtolower($term).'%']));
        }
        if ($category = $filters['category'] ?? '') {
            $query->where('category', $category);
        }
        $documents = $query->orderByDesc('date')->orderByDesc('id')->get()->keyBy('id')
            ->map(fn (object $document): array => (array) $document)->all();
        $categories = DB::table('documents')->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();

        return view('dashboard', compact('documents', 'categories', 'selectedMonth', 'monthLabel', 'monthlyTotal', 'monthNavigation'));
    }

    public function show(int $id): View
    {
        $document = DB::table('documents')->select('id', 'title', 'supplier', 'date', 'category', 'amount')->selectRaw('content IS NOT NULL AS has_file')->find($id);
        abort_unless($document, 404);

        return view('document', ['document' => (array) $document]);
    }

    public function download(int $id): Response
    {
        $document = DB::table('documents')->find($id);
        abort_unless($document && $document->content !== null, 404);

        return response(base64_decode($document->content, true), 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="documento-'.$id.'.'.$document->extension.'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['Super_user', 'amministratore'], true), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'], 'supplier' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date_format:Y-m-d'], 'category' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('file');
        unset($data['file']);
        $data['category'] = $data['category'] ?? null;
        DB::table('documents')->insert($data + [
            'uploaded_by' => $request->user()->id, 'content' => $file ? base64_encode($file->get()) : null,
            'mime_type' => $file?->getMimeType(), 'extension' => $file?->extension(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return to_route('dashboard')->with('status', 'Documento caricato.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->role === 'Super_user', 403);
        abort_unless(DB::table('documents')->where('id', $id)->delete(), 404);

        return to_route('dashboard')->with('status', 'Documento eliminato.');
    }
}
