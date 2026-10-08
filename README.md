# EdilCise · prototipo Laravel / Blade

Proposta UI per il portale del Condominio EdilCise, Somma Vesuviana. Laravel 13, viste Blade e CSS; nessun React, Inertia o compilazione frontend richiesta.

## Avvio locale

Requisiti: PHP 8.3 o superiore, Composer e SQLite.

Le dipendenze e la configurazione locale sono già presenti in questo workspace. Avviare:

```sh
php artisan serve
```

Aprire http://127.0.0.1:8000. Dalla pagina login, scegliere **Esplora la demo**. Sono disponibili dashboard, ricerca per titolo/fornitore, filtro categoria, dettaglio dimostrativo del documento e profilo con validazione server dei moduli.

Per una nuova copia: `composer run setup`, quindi `php artisan serve`. Non occorre eseguire npm. Il file originale del logo è `public/img/Logo.PNG`; la versione trasparente usata nelle viste è `public/img/edilcise-transparent.png`. Il prompt imagegen è documentato in `design/assets/logo-edit.md`.

Su mobile l’archivio usa schede documento, i moduli una singola colonna e la navigazione si compatta. I controlli hanno dimensioni adatte al tocco.

## Perimetro

Questa versione serve soltanto alla revisione dell'interfaccia. Non contiene autenticazione operativa, modifica di utenti/password, documenti reali o bot Telegram. Le pagine demo sono pubbliche e non devono contenere dati reali. I moduli non persistono i dati e dichiarano questa limitazione nell'interfaccia. Le password non vengono reinserite nei moduli in caso di errori.

Il piano di sviluppo è in `PIANO.md`. Il precedente prototipo statico in `design/` resta una proposta storica; la versione aggiornata è quella servita da Laravel.

## Verifiche

```sh
php artisan test
php artisan view:cache
```

I test verificano rendering, logo, filtri, documenti inesistenti, validazione e assenza di password nei dati riproposti dopo gli errori. La resa visiva richiede ancora revisione nel browser.
