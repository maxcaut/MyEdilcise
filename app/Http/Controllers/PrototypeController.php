<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrototypeController extends Controller
{
    private function documents(): array
    {
        return [
            1 => ['title' => 'Manutenzione ascensore', 'supplier' => 'Ascensori Campania', 'date' => '08/10/2026', 'category' => 'Manutenzione', 'amount' => 480],
            2 => ['title' => 'Energia elettrica · settembre', 'supplier' => 'Energia Servizi', 'date' => '05/10/2026', 'category' => 'Utenze', 'amount' => 325],
            3 => ['title' => 'Pulizia scale · settembre', 'supplier' => 'Pulito & Cura', 'date' => '01/10/2026', 'category' => 'Pulizia', 'amount' => 280],
            4 => ['title' => 'Polizza fabbricato 2026', 'supplier' => 'Assicurazioni Italia', 'date' => '24/09/2026', 'category' => 'Assicurazione', 'amount' => 2100],
            5 => ['title' => 'Riparazione cancello', 'supplier' => 'Officina Verde', 'date' => '18/09/2026', 'category' => 'Manutenzione', 'amount' => 715],
            6 => ['title' => 'Acqua · terzo trimestre', 'supplier' => 'Acqua Metropolitana', 'date' => '15/09/2026', 'category' => 'Utenze', 'amount' => 420],
        ];
    }

    public function dashboard(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'string', 'max:50']]);
        $documents = $this->documents();
        $categories = array_values(array_unique(array_column($documents, 'category')));
        $term = mb_strtolower(trim($filters['q'] ?? ''));
        $category = $filters['category'] ?? '';
        $documents = array_filter($documents, fn (array $document): bool => ($term === '' || str_contains(mb_strtolower($document['title'].' '.$document['supplier']), $term)) &&
            ($category === '' || $document['category'] === $category)
        );

        return view('dashboard', compact('documents', 'categories'));
    }

    public function document(int $id): View
    {
        $document = $this->documents()[$id] ?? null;
        abort_if($document === null, 404);

        return view('document', compact('document'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        return to_route('profile')->with('status', 'Dati validi. Questa è una demo: nessuna modifica è stata salvata.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        return to_route('profile')->with('status', 'Formato valido. Questa è una demo: la password non è stata verificata né modificata.');
    }
}
