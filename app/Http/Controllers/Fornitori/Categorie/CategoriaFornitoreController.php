<?php

namespace App\Http\Controllers\Fornitori\Categorie;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fornitore\Categoria\CreateCategoriaFornitoreRequest;
use App\Http\Requests\Fornitore\Categoria\UpdateCategoriaFornitoreRequest;
use App\Models\CategoriaFornitore;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le categorie di fornitore, che da questa beta le gestisce l'amministratore.
 *
 * ## Perché esiste
 *
 * Il programma ne spediva **nove** — Elettricista, Idraulico, Muratore, Giardiniere, Servizi di
 * pulizia, Sicurezza e antincendio, Ascensorista, Azienda multiservizi, Altro — e **non c'era nessun
 * modo di toccarle**: niente controller, niente rotte, niente pagina. Si cambiavano solo modificando
 * il seeder o il database a mano.
 *
 * E si vedeva nell'uso: misurato il 30/08/2026, **sei fornitori su otto non avevano categoria** e
 * delle nove ne erano usate due. Mancavano autospurgo, disinfestazione, caldaie, portierato, fabbro,
 * imbianchino, assicurazioni, utenze: la migrazione `seed_categorie_fornitore` ne aggiunge dieci.
 *
 * ⚠️ **Ma le dieci non sono la soluzione, sono un punto di partenza migliore.** Il motivo per cui
 * questa pagina esiste è che **qualunque elenco fisso è sbagliato per qualcuno**, e dopo le dieci ne
 * mancheranno altre; l'unica risposta che regge è che se le faccia l'amministratore.
 *
 * ⚠️ **E non è un'etichetta decorativa:** `docs/roadmap.md` prevede lo «Smart Ledger Suggester», che
 * suggerisce il conto contabile da `categoria_fornitore` × `voce_deliberata`. Una categoria scelta
 * dall'amministratore vale più di una scelta da noi.
 *
 * ## ⚠️ Perché le nove iniziali non stanno più in un seeder
 *
 * Dal momento in cui si può cancellare una categoria, un seeder con `firstOrCreate` **la farebbe
 * risorgere** al primo `db:seed`. Le nove sono state spostate nella migrazione
 * `seed_categorie_fornitore`, che per costruzione gira una volta sola. Vedi la Coda 103: le altre
 * tre tabelle master hanno ancora quel difetto, e quella dei documenti è già cancellabile.
 */
class CategoriaFornitoreController extends Controller
{
    use HandleFlashMessages;

    public function index(): Response
    {
        return Inertia::render('fornitori/categorie/CategorieList', [
            // ⚠️ **I fornitori arrivano con l'elenco, non solo il loro numero.**
            //
            // Il conteggio serviva a dire «questa non si può cancellare» **prima** che l'utente ci
            // provi. Ma un numero risponde a «quanti» e non a «quali», e con tre fornitori uno sa
            // che sono tre e non sa chi: deve cercarseli, e non c'è nessuna schermata che sappia
            // rispondergli — l'elenco fornitori **non ha né la colonna della categoria né un filtro
            // su di essa**, pur ricevendo il dato in ogni riga.
            //
            // Quindi si mandano le righe, e la colonna monta `AnagraficheStack`, lo stesso
            // componente dell'elenco condomini e di quello delle unità: si vedono le iniziali, si
            // apre il pannello, e da lì si entra nella scheda del fornitore per cambiargli
            // categoria. Idea di Vincenzo, e il componente esisteva già.
            //
            // `select()` esplicita e non il modello intero: di un fornitore qui servono tre campi,
            // e la scheda del fornitore ne ha una quarantina — che finirebbero tutti sul filo per
            // ogni riga di ogni categoria.
            'categorie' => CategoriaFornitore::with([
                'fornitori' => fn ($q) => $q
                    ->select('id', 'categoria_id', 'ragione_sociale', 'indirizzo')
                    ->orderBy('ragione_sociale'),
            ])
                ->orderBy('name')
                ->get()
                ->map(fn (CategoriaFornitore $c) => [
                    'id'              => $c->id,
                    'name'            => $c->name,
                    'description'     => $c->description,

                    // Il conteggio resta, e non è ridondante: è quello che spegne la voce
                    // «Elimina» nel menù, e non deve dipendere da quante righe la schermata ha
                    // deciso di disegnare.
                    'fornitori_count' => $c->fornitori->count(),

                    // La forma è quella che `AnagraficheStack` si aspetta — `nome`, `indirizzo`,
                    // `url` — non quella del modello: l'adattamento si fa qui, una volta, invece
                    // che nel componente condiviso, che non deve sapere cos'è un fornitore.
                    'fornitori' => $c->fornitori->map(fn ($f) => [
                        'id'        => $f->id,
                        'nome'      => $f->ragione_sociale,
                        'indirizzo' => $f->indirizzo,
                        'url'       => route('admin.fornitori.show', $f->id),
                    ])->values(),
                ]),
        ]);
    }

    public function store(CreateCategoriaFornitoreRequest $request): RedirectResponse
    {
        try {
            CategoriaFornitore::create($request->validated());

            return back()->with($this->flashSuccess(__('fornitori.categorie.success_create')));
        } catch (\Throwable $e) {
            Log::error('Errore creando una categoria fornitore: ' . $e->getMessage());

            return back()->with($this->flashError(__('fornitori.categorie.error_create')));
        }
    }

    public function update(UpdateCategoriaFornitoreRequest $request, CategoriaFornitore $categoria): RedirectResponse
    {
        try {
            $categoria->update($request->validated());

            return back()->with($this->flashSuccess(__('fornitori.categorie.success_update')));
        } catch (\Throwable $e) {
            Log::error('Errore aggiornando una categoria fornitore: ' . $e->getMessage());

            return back()->with($this->flashError(__('fornitori.categorie.error_update')));
        }
    }

    /**
     * ⚠️ **Si rifiuta se qualche fornitore la usa, e dice quanti.**
     *
     * Il vincolo a database è `nullOnDelete` (`fornitori.categoria_id`), quindi il database
     * lascerebbe cancellare e **azzererebbe in silenzio** la categoria dei fornitori che la usavano:
     * nessun errore, nessun avviso, e un dato perso che nessuno ricollega a questa azione. La
     * guardia sta qui, ed è la stessa forma che hanno già le categorie dei documenti.
     */
    public function destroy(CategoriaFornitore $categoria): RedirectResponse
    {
        try {
            $quanti = $categoria->fornitori()->count();

            if ($quanti > 0) {
                return back()->with($this->flashInfo(
                    trans_choice('fornitori.categorie.in_uso', $quanti, ['nome' => $categoria->name, 'quanti' => $quanti])
                ));
            }

            $categoria->delete();

            return back()->with($this->flashSuccess(__('fornitori.categorie.success_delete')));
        } catch (\Throwable $e) {
            Log::error('Errore eliminando una categoria fornitore: ' . $e->getMessage());

            return back()->with($this->flashError(__('fornitori.categorie.error_delete')));
        }
    }
}
