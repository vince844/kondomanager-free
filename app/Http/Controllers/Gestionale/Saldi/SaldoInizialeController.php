<?php

namespace App\Http\Controllers\Gestionale\Saldi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Saldi\CreateSaldoRequest;
use App\Http\Requests\Gestionale\Saldi\UpdateSaldoRequest;
use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Services\Gestionale\SaldoEsercizioService;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use App\Traits\HandleFlashMessages;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SaldoInizialeController extends Controller
{
    use HasCondomini;
    use HasEsercizio;
    use HandleFlashMessages;

    public function __construct(
        private readonly SaldoEsercizioService $saldoService,
    ) {}

    public function index(Condominio $condominio)
    {
        $esercizioAttivo = $this->getEsercizioCorrente($condominio);

        if (!$esercizioAttivo) {
            return back()->with($this->flashError('Nessun esercizio aperto trovato.'));
        }

        $immobili = $condominio->immobili()
            ->with([
                'anagrafiche',
                'saldi' => function ($q) use ($esercizioAttivo) {
                    $q->where('esercizio_id', $esercizioAttivo->id)
                      // pianoRate serve alla UI per dire QUALE piano tiene il lucchetto,
                      // invece di limitarsi a mostrare un'icona muta.
                      ->with(['gestione:id,nome,tipo', 'anagrafica:id,nome', 'pianoRate:id,nome']);
                },
                'palazzina',
                'scala',
            ])
            ->get();

        $this->esponiLucchettoCalcolato($immobili);

        $gestioni = Gestione::where('condominio_id', $condominio->id)
            ->where('attiva', true)
            // --- MODIFICA 1: Aggiunto 'saldo_applicato' al get() per farlo arrivare al frontend (serve per il lucchetto) ---
            ->get(['id', 'nome', 'tipo', 'saldo_applicato']);

        return Inertia::render('gestionale/saldi/SaldiList', [
            'condominio' => $condominio,
            // Popola il selettore di condominio nell'header: senza questa lista
            // PageHeaderGuide mostra il nome come testo fisso e la pagina resta
            // l'unica dello Struttura da cui non si può cambiare condominio.
            'condomini'  => $this->getCondomini(),
            'esercizio'  => $esercizioAttivo,
            'immobili'   => $immobili,
            'gestioni'   => $gestioni,
        ]);
    }

    /**
     * Porta al frontend il lucchetto **calcolato**, non il flag grezzo.
     *
     * `Saldo::eBloccato()` è l'autorità dalla beta.33: se il saldo ha un piano risponde
     * `PianoRate::eImmutabile()` — rate emesse o incassi registrati — e solo in assenza di
     * piano ripiega su `is_applicato`. Quel metodo però non arrivava mai a Vue: qui si
     * serializza il model grezzo e `Saldo` non ha `$appends`, quindi il pannello decideva sul
     * booleano nudo. Ma `is_applicato` viene acceso alla **generazione** del piano
     * (`SaldoEsercizioService`), non alla sua emissione: fra i due momenti il server accettava
     * la correzione e l'interfaccia mostrava il lucchetto.
     *
     * Non è solo un'icona di troppo. La modale consiglia «annulla le emissioni e il saldo torna
     * modificabile»: l'annullamento funziona davvero, ma nessuno rimette `is_applicato` a
     * false, quindi l'utente seguiva un'istruzione stampata nell'applicazione senza ottenere
     * niente — ed è il percorso della segnalazione per cui la beta.32 è nata.
     *
     * **Una volta per piano, non per saldo.** `eImmutabile()` fa due `whereHas`: chiamarlo su
     * ogni riga significherebbe due query per saldo su una pagina che ne mostra decine.
     */
    private function esponiLucchettoCalcolato(Collection $immobili): void
    {
        $saldi = $immobili->pluck('saldi')->flatten();

        $immutabilita = PianoRate::whereIn('id', $saldi->pluck('piano_rate_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn (PianoRate $piano): array => [$piano->id => $piano->eImmutabile()]);

        foreach ($saldi as $saldo) {
            // Un `piano_rate_id` che non risolve è un lucchetto orfano: si resta prudenti e
            // si tiene bloccato, esattamente come fa `eBloccato()` senza piano.
            $saldo->e_bloccato = $saldo->piano_rate_id !== null
                ? ($immutabilita[$saldo->piano_rate_id] ?? true)
                : (bool) $saldo->is_applicato;
        }
    }

    public function store(CreateSaldoRequest $request, Condominio $condominio)
    {
        $validated = $request->validated();

        $esercizioAttivo = $this->getEsercizioCorrente($condominio);

        abort_unless(
            $condominio->immobili()->where('id', $validated['immobile_id'])->exists(),
            403, 'Immobile non appartiene a questo condominio.'
        );
        abort_unless(
            $condominio->gestioni()->where('id', $validated['gestione_id'])->exists(),
            403, 'Gestione non appartiene a questo condominio.'
        );

        Saldo::create([
            'esercizio_id'   => $esercizioAttivo->id,
            'condominio_id'  => $condominio->id,
            'immobile_id'    => $validated['immobile_id'],
            'anagrafica_id'  => $validated['anagrafica_id'] ?? null,
            'gestione_id'    => $validated['gestione_id'],
            'saldo_iniziale' => $validated['saldo_iniziale'],
            'origine'        => 'manuale',
        ]);

        return back()->with($this->flashSuccess('Saldo aggiunto al Wallet con successo.'));
    }

    public function update(UpdateSaldoRequest $request, Condominio $condominio, Saldo $saldo)
    {
        // Ownership, muro contabile e appartenenza gestione
        // sono già verificati in UpdateSaldoRequest::after()
        $saldo->update($request->validated());

        return back()->with($this->flashSuccess('Saldo aggiornato.'));
    }

    /**
     * Riapre a mano un lucchetto che nessun piano rate rivendica.
     *
     * Serve ai due casi che il sistema non sa risolvere da solo: i saldi bloccati
     * prima della beta.32 (quando il lucchetto non aveva un titolare) e quelli che
     * la migrazione di riparazione lascia deliberatamente chiusi perché più piani
     * della stessa gestione contengono quote di saldo e attribuirli a caso
     * rischierebbe un doppio addebito.
     *
     * Non è una scorciatoia: se il saldo ha un titolare, la strada resta agire sul
     * piano che lo tiene.
     */
    public function sblocca(Condominio $condominio, Saldo $saldo)
    {
        abort_unless($saldo->condominio_id === $condominio->id, 403);

        // I debiti verso fornitori vivono nella stessa tabella con is_applicato=true
        // proprio per restare fuori dai piani rate: non sono lucchetti da aprire.
        abort_if(
            $saldo->fornitore_id !== null,
            403,
            'Questo non è un saldo di un condòmino ma un debito verso un fornitore: non va sbloccato.'
        );

        abort_if(
            $saldo->piano_rate_id !== null,
            403,
            "Questo saldo è intestato al piano rate «{$saldo->pianoRate?->nome}»: per liberarlo agisci su quel piano, non a mano."
        );

        abort_unless($saldo->is_applicato, 400, 'Questo saldo è già libero.');

        $saldo->update(['is_applicato' => false]);

        Log::info('Sblocco manuale di un saldo senza titolare', [
            'saldo_id'       => $saldo->id,
            'gestione_id'    => $saldo->gestione_id,
            'saldo_iniziale' => $saldo->saldo_iniziale,
            'user_id'        => auth()->id(),
        ]);

        // Il flag di gestione è un derivato: va ricalcolato, o resta acceso
        // raccontando un blocco che non c'è più.
        if ($saldo->gestione) {
            $this->saldoService->allineaFlagGestione($saldo->gestione);
        }

        return back()->with($this->flashSuccess('Saldo sbloccato: nessun piano rate lo rivendicava.'));
    }

    public function destroy(Condominio $condominio, Saldo $saldo)
    {
        abort_unless($saldo->condominio_id === $condominio->id, 403);

        // La riga, non la gestione. Il flag `gestioni.saldo_applicato` è un
        // derivato dalla beta.32: usarlo qui come autorità significava vietare
        // di cancellare un saldo LIBERO solo perché un altro saldo della stessa
        // gestione era finito in un piano — cioè impedire la correzione in
        // corso di gestione, che è un bisogno legittimo e diverso.
        abort_if(
            $saldo->eBloccato(),
            403,
            $saldo->pianoRate
                ? "Non puoi eliminare questo saldo: è incluso nel piano rate «{$saldo->pianoRate->nome}», già emesso o incassato."
                : 'Non puoi eliminare un saldo già applicato a un piano rate.'
        );

        $saldo->delete();

        return back()->with($this->flashSuccess('Saldo rimosso dal Wallet.'));
    }
}