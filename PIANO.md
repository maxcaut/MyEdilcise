# MyEdilcise — Piano di azione e proposta UI

## Obiettivo e perimetro attuale
Un amministratore invia documenti di spesa a un bot Telegram. I condomini autorizzati consultano i documenti del proprio condominio nella web app. Questa fase produce soltanto piano e prototipo grafico: nessun backend, account reale, caricamento o integrazione Telegram è attivo.

## Stack scelto
Laravel, come richiesto, con viste Blade per login, dashboard e profilo. Autenticazione, autorizzazioni, validazione dei moduli, ricerca, code e integrazione Telegram saranno gestiti da Laravel nella stessa applicazione. CSS per il sistema visivo e JavaScript essenziale soltanto per le interazioni locali, come l'anteprima dei documenti.

Nessuna dipendenza da React o Inertia e nessun frontend separato. Ricerca e filtri useranno parametri GET; i moduli saranno gestiti con validazione lato server, protezione CSRF e messaggi di esito nelle viste Blade. Livewire non è necessario per il perimetro iniziale.

PostgreSQL per i dati e storage privato compatibile S3 per gli allegati restano proposte da confermare in base all'ambiente di hosting. La scelta di Laravel non modifica il prototipo grafico attuale, che verrà tradotto in layout e componenti Blade durante l'implementazione.

Riferimenti ufficiali consultati: https://laravel.com/starter-kits · https://laravel.com/framework/docs/frontend

## Fasi e risultati attesi
1. **Definizione dei flussi.** Ricevere logo, chiarire uno o più condomini, ruoli, inviti, formati di documento, campi della spesa e modalità di pubblicazione. Risultato: requisiti e criteri di accettazione condivisi.
2. **Design dell'interfaccia.** Revisionare login, dashboard e profilo, adattare palette al logo e definire viste mobile, stati vuoti, caricamento ed errori. Risultato: schermate approvate prima dell'implementazione.
3. **Accesso e struttura dati.** Implementare utenti, condomini, appartenenze e ruoli; login, recupero password, profilo e inviti. Risultato: ogni utente accede soltanto ai condomini autorizzati.
4. **Acquisizione Telegram.** Collegare gli identificativi Telegram autorizzati agli amministratori, ricevere gli allegati via webhook e processarli in coda. Il bot chiede condominio e dati mancanti, conferma l'acquisizione e segnala gli errori. Risultato: documenti acquisiti una sola volta anche in caso di ritrasmissione degli aggiornamenti.
5. **Archivio documenti.** Implementare ricerca, categorie, filtro temporale, anteprima e download protetti. Risultato: consultazione semplice da desktop e telefono.
6. **Verifica e rilascio.** Verificare isolamento tra condomini, accessi ai file, cambio password, inviti, duplicati e fallimenti Telegram; predisporre backup e ambiente di prova. Risultato: MVP verificabile prima della pubblicazione.

## Flusso Telegram proposto, da validare
Chat privata con il bot → verifica amministratore → scelta condominio se necessaria → invio PDF o foto → inserimento titolo, data, categoria e importo eventuale → conferma → pubblicazione → disponibilità in dashboard.

Per il primo MVP propongo una conferma esplicita dell'amministratore prima della pubblicazione. OCR e classificazione automatica sono estensioni successive, da valutare. Limiti e formati Telegram andranno verificati sulla documentazione Bot API in fase di integrazione.

## Dati e autorizzazioni
- Utente, condominio e appartenenza con ruolo; lo stesso utente può appartenere a più condomini se richiesto.
- Spesa: condominio, titolo, fornitore, data, categoria, importo e valuta. Documento: file privato, formato, autore, origine, date e stato di pubblicazione; relazione con la spesa quando presente.
- I riepiloghi devono sommare le spese, evitando di contare più volte gli allegati. Il totale non rappresenta un saldo né la quota dovuta dal singolo condomino.
- Amministratore: carica e pubblica per i condomini assegnati. Condomino: consulta i documenti pubblicati nei condomini di appartenenza. Download e anteprime richiedono gli stessi controlli della dashboard.
- Webhook protetto, credenziali sul server, validazione degli allegati e tracciamento delle pubblicazioni. Definire conservazione e cancellazione dei documenti prima del rilascio.

## Proposta grafica
Identità aggiornata al logo originale del Condominio EdilCise, Somma Vesuviana, disponibile in `public/img/Logo.PNG`. Fondo caldo chiaro, superfici bianche, verde bosco e salvia con accenti terracotta ripresi dal logo; bordi leggeri e carattere sans serif di sistema. La direzione è un archivio residenziale ordinato e leggibile.

**Login:** area di identità e form email/password, recupero password e indicazione di contattare l'amministratore per ottenere un account.

**Dashboard:** condominio attivo, riepiloghi dimostrativi, ricerca, categoria, elenco con titolo, fornitore, data e importo. L'anteprima attuale descrive il comportamento futuro, senza contenere file reali. Su schermi piccoli la navigazione si compatta e l’archivio usa schede documento senza scorrimento orizzontale; filtri e moduli si dispongono in verticale.

**Profilo:** moduli distinti per dati personali e password; il condominio assegnato non è modificabile dall'utente nel prototipo.

## Come consultare il prototipo
Avviare `php artisan serve` e aprire http://127.0.0.1:8000. La versione attuale è servita da Laravel con layout e componenti Blade e CSS statico, senza React o build frontend. Il login propone “Esplora la demo”; ricerca e categoria sono gestite dal server su sei documenti inventati. I moduli del profilo validano i dati sul server ma non li persistono. Non utilizzare credenziali reali. Il vecchio file `design/index.html` resta come proposta storica.

## Decisioni da prendere con i prossimi dettagli
Amministrazione di uno o più stabili; registrazione tramite invito o approvazione; dati obbligatori della spesa; pubblicazione immediata o confermata; gestione correzioni e cancellazioni; eventuali notifiche ai condomini. Queste decisioni precedono lo sviluppo del backend.

## Stato dell’interfaccia Laravel
Progetto Laravel installato; login, dashboard, dettaglio dimostrativo e profilo in Blade. Logo originale collegato. I test automatici coprono rendering, ricerca, filtri, errori e moduli demo. Backend operativo, autenticazione reale e Telegram restano nelle fasi successive. La resa visiva resta da revisionare nel browser.

Logo aggiornato con variante PNG trasparente in `public/img/edilcise-transparent.png`; originale conservato.
