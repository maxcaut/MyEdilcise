<?php

namespace App;

class TelegramMessages
{
    /** @var array<string, array{label: string, default: string}> */
    public const DEFINITIONS = [
        'not_linked' => ['label' => 'Account non collegato', 'default' => 'Collega prima Telegram dal tuo profilo {app_name}.'],
        'unauthorized' => ['label' => 'Account non autorizzato', 'default' => 'Il tuo account non è autorizzato a caricare documenti.'],
        'cancelled' => ['label' => 'Caricamento annullato', 'default' => 'Caricamento annullato. Puoi inviare un nuovo documento.'],
        'draft_pending' => ['label' => 'Documento già in corso', 'default' => 'Completa il documento in corso oppure scrivi /annulla prima di inviarne un altro.'],
        'file_too_large' => ['label' => 'File troppo grande', 'default' => 'Il file supera il limite di 10 MB.'],
        'uploaded' => ['label' => 'Documento caricato', 'default' => 'Documento caricato su {app_name} ✅'],
        'instructions' => ['label' => 'Istruzioni di invio', 'default' => "Invia un PDF, JPG o PNG fino a 10 MB.\nPuoi aggiungere una didascalia, una voce per riga:\nTitolo: Fattura ottobre\nFornitore: Rossi Materiali\nData: 08/10/2026\nImporto: 125,50\nCategoria: Manutenzione\n\nTi chiederò i dati mancanti. /annulla interrompe il caricamento."],
        'ask_title' => ['label' => 'Richiesta titolo', 'default' => 'Qual è il titolo del documento?'],
        'ask_supplier' => ['label' => 'Richiesta fornitore', 'default' => 'Chi è il fornitore?'],
        'ask_date' => ['label' => 'Richiesta data', 'default' => 'Qual è la data del documento? Scrivi GG/MM/AAAA.'],
        'ask_amount' => ['label' => 'Richiesta importo', 'default' => 'Qual è l’importo in euro? Esempio: 125,50.'],
        'ask_category' => ['label' => 'Richiesta categoria', 'default' => 'Qual è la categoria? Scrivi /salta per lasciarla vuota.'],
        'expired_link' => ['label' => 'Collegamento scaduto', 'default' => 'Collegamento scaduto o già utilizzato. Generane uno nuovo dal tuo profilo {app_name}.'],
        'already_linked' => ['label' => 'Account Telegram già collegato', 'default' => 'Questo account Telegram è già collegato. Scollegalo dal profilo precedente prima di continuare.'],
        'linked' => ['label' => 'Collegamento completato', 'default' => 'Telegram collegato a {app_name} ✅ Invia un PDF, JPG o PNG fino a 10 MB per iniziare.'],
        'invalid_amount_format' => ['label' => 'Formato importo non valido', 'default' => 'Importo non valido. Scrivi ad esempio 125,50.'],
        'invalid_title' => ['label' => 'Titolo non valido', 'default' => 'Scrivi un titolo da 1 a 150 caratteri.'],
        'invalid_supplier' => ['label' => 'Fornitore non valido', 'default' => 'Scrivi un fornitore da 1 a 150 caratteri.'],
        'invalid_date' => ['label' => 'Data non valida', 'default' => 'Data non valida. Scrivi una data reale nel formato GG/MM/AAAA.'],
        'invalid_amount' => ['label' => 'Importo non valido', 'default' => 'Importo non valido. Scrivi un valore da 0 a 99999999,99 con al massimo due decimali.'],
        'invalid_category' => ['label' => 'Categoria non valida', 'default' => 'Scrivi una categoria fino a 50 caratteri oppure /salta.'],
        'summary_heading' => ['label' => 'Introduzione al riepilogo', 'default' => 'Controlla il documento:'],
        'summary_footer' => ['label' => 'Istruzioni di conferma', 'default' => 'Premi Conferma per caricarlo oppure Annulla.'],
        'invalid_file' => ['label' => 'Formato file non valido', 'default' => 'Invia un PDF, JPG o PNG valido, fino a 10 MB.'],
    ];

    public function __construct(private AppSettings $settings) {}

    public function text(string $key): string
    {
        $custom = $this->settings->values()['telegram_message_'.$key] ?? null;
        $text = filled($custom) ? $custom : self::DEFINITIONS[$key]['default'];

        return strtr($text, ['{app_name}' => config('app.name')]);
    }
}
