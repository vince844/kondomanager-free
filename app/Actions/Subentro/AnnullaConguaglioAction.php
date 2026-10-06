<?php

namespace App\Actions\Subentro;

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Toglie **insieme** le righe di conguaglio di un passaggio (1.11.0-beta.31, B2 S6, voce 8).
 *
 * La coppia in `saldi` (credito a chi esce, debito a chi entra, invariante 19: somma zero) nasce dal
 * criterio di legge; se le parti hanno regolato il conguaglio in un altro modo l'amministratore la
 * toglie da qui — mai una gamba sola dal Wallet (`SaldoInizialeController::destroy` la rifiuta) — con
 * una nota che resta sul passaggio (`nota_annullamento_conguaglio`, `conguaglio_annullato_il`).
 *
 * Solo finché le righe sono **libere** (`is_applicato` falso): assorbite da un piano, il numero sta già
 * nelle quote e toglierlo qui lascerebbe il piano a raccontare un conguaglio che non esiste più. In
 * quel caso si dice quale piano e quale strada, col criterio di `PianoRate::eImmutabile()`: se non ha
 * emesso nulla a giornale né incassato, lo si riporta in bozza e lo si elimina (il lucchetto si riapre) e
 * si torna qui; se ha emesso o incassato, resta il saldo manuale di segno opposto. Le righe delle
 * pertinenze (subentri figli) vanno con il padre.
 */
final class AnnullaConguaglioAction
{
    public function execute(Subentro $subentro, string $nota): int
    {
        $nota = trim($nota);
        if (mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota_annullamento_conguaglio' => 'Scrivi in almeno dieci caratteri come le parti hanno regolato il conguaglio: resta sul passaggio.']);
        }

        return DB::transaction(function () use ($subentro, $nota) {
            $padre = Subentro::conAnnullati()->whereKey($subentro->subentro_padre_id ?? $subentro->id)->lockForUpdate()->firstOrFail();
            // Il passaggio annullato mentre si annullava il conguaglio (beta.37): un rifiuto leggibile, non un 404.
            if ($padre->annullato()) {
                throw ValidationException::withMessages(['conguaglio' => 'Questo passaggio è stato annullato, e il suo conguaglio con lui: non c\'è più niente da annullare.']);
            }
            if ($padre->conguaglioAnnullato()) {
                throw ValidationException::withMessages(['conguaglio' => 'Il conguaglio di questo passaggio è già stato annullato.']);
            }

            // Decisione 65 (1.11.0-beta.44): con l'arretrato agli eredi la coppia e l'arretrato sono un conto solo — l'arretrato è la
            // posizione del defunto meno la coppia —, e togliere la coppia lascerebbe a ciascun erede una cifra senza senso.
            if ($padre->arretratoAgliEredi()) {
                throw ValidationException::withMessages(['conguaglio' => 'Con l\'arretrato agli eredi il conguaglio e l\'arretrato fanno un conto solo: insieme danno a ciascun erede la sua parte dell\'anno intero, e il conguaglio da solo non si annulla. Se gli eredi hanno regolato fra loro in un altro modo, annulla il passaggio e registralo di nuovo con l\'arretrato a nome del defunto; poi annulla il conguaglio.']);
            }

            $ids = $padre->pertinenze()->pluck('id')->push($padre->id)->all();
            $righe = Saldo::whereIn('subentro_id', $ids)->with('pianoRate')->lockForUpdate()->get();
            if ($righe->isEmpty()) {
                throw ValidationException::withMessages(['conguaglio' => 'Questo passaggio non ha righe di conguaglio da annullare.']);
            }

            // Decisione 67 (3), rilievo X6 della Fase 1-bis: le righe di questo conguaglio sono fra le fonti dell'arretrato agli eredi di una
            // successione dopo. Toglierle lascerebbe al defunto un credito finto e agli eredi un debito calcolato su righe che non ci sono più.
            if (($successione = Subentro::successioneCheLeggeISaldi($righe->pluck('id')->all(), (int) $padre->condominio_id)) !== null) {
                // Rilievo GC12 del giro: le righe degli eredi possono essere un credito, e conta la riga del defunto, non tutte e due.
                throw ValidationException::withMessages(['conguaglio' => sprintf('Una riga di questo conguaglio è fra le cifre da cui è calcolato l\'arretrato di %s, passato agli eredi con la successione del %s: annullarlo lascerebbe sbagliate le righe degli eredi. Annulla prima la successione dallo storico dell\'unità, poi questo conguaglio, e registra di nuovo la successione; se la successione non si può più annullare, il conguaglio resta, e un accordo diverso fra le parti si scrive con un saldo manuale.',
                    $successione->uscente?->nome ?? 'questa persona', \Carbon\CarbonImmutable::parse($successione->decorrenza)->locale('it')->translatedFormat('j F Y'))]);
            }

            $assorbite = $righe->filter(fn (Saldo $s) => (bool) $s->is_applicato);
            if ($assorbite->isNotEmpty()) {
                $frase = self::fraseAssorbite($assorbite, 'Il conguaglio è già stato assorbito da un piano rate e non si annulla da qui.', 'torna qui');
                throw ValidationException::withMessages(['conguaglio' => $frase]);
            }

            if ((int) $righe->sum('saldo_iniziale') !== 0) {
                throw ValidationException::withMessages(['conguaglio' => 'Le righe del conguaglio non sommano zero: qualcosa è stato modificato a mano. Controlla i saldi della gestione prima di annullare.']);
            }

            // Decisione 46: ciò che le parti hanno regolato fra loro resta nel registro, per gestione — le righe in saldi si
            // cancellano, e la strada della 43 deve poter dire la cifra.
            $uscenteId = (int) $padre->anagrafica_uscente_id;
            $nomi = DB::table('gestioni')->whereIn('id', $righe->pluck('gestione_id')->unique())->pluck('nome', 'id');
            $regolato = $righe->filter(fn (Saldo $r) => (int) $r->anagrafica_id !== $uscenteId)->groupBy('gestione_id')
                ->map(fn ($g, $id) => ['gestione_id' => (int) $id, 'gestione' => (string) ($nomi[$id] ?? '?'), 'importo' => (int) $g->sum('saldo_iniziale')])
                ->values()->all();

            // Rilievo X8 della Fase 1-bis: con l'arretrato a nome del defunto la cifra che resta a lui era la sua posizione meno la coppia;
            // tolta la coppia, torna a contare anche quella parte. Dalle righe tolte, non ricostruita.
            $registro = array_replace($padre->registro ?? [], ['regolato_fuori' => $regolato]);
            if (($registro['arretrato']['scelta'] ?? null) === Subentro::ARRETRATO_AL_DEFUNTO) {
                $registro['arretrato']['resta'] = (int) ($registro['arretrato']['resta'] ?? 0)
                    - (int) $righe->filter(fn (Saldo $r) => (int) $r->anagrafica_id === $uscenteId)->sum('saldo_iniziale');
            }

            $tolte = Saldo::whereIn('id', $righe->pluck('id'))->delete();
            $padre->update(['conguaglio_annullato_il' => now(), 'nota_annullamento_conguaglio' => $nota, 'registro' => $registro]);

            return $tolte;
        });
    }

    /**
     * Che cosa fare quando il conguaglio è già assorbito da un piano, col criterio di `PianoRate::eImmutabile()` — rate
     * emesse a giornale o movimenti di denaro, non lo stato del piano (verifica S6, R9): `is_applicato` si accende alla
     * GENERAZIONE, e un piano approvato ma non ancora emesso è lo stato normale fra la delibera e l'emissione. Finché non
     * ha emesso nulla la strada pulita esiste: torna in bozza, elimina il piano (il lucchetto si riapre), torna qui.
     * La usa anche l'annullamento del passaggio (1.11.0-beta.37): una regola, due porte.
     *
     * @param \Illuminate\Support\Collection<int, Saldo> $assorbite righe con `is_applicato`, con `pianoRate` caricato
     */
    public static function fraseAssorbite(\Illuminate\Support\Collection $assorbite, string $apertura, string $ritorno, bool $perIlPassaggio = false, array $presiDaSeguire = [], bool $conArretrato = false): string
    {
        $piani = $assorbite->map(fn (Saldo $s) => $s->pianoRate)->filter()->unique('id');
        $correggibili = $piani->reject(fn ($p) => $p->eImmutabile())->pluck('nome')->all();
        $frase = $apertura;
        if ($correggibili !== []) {
            $frase .= sprintf(' %s «%s» non %s ancora emesso nulla: se %s approvat%s riportal%s in bozza, elimina il piano (il lucchetto si riapre) e %s; poi rifai il piano.',
                count($correggibili) === 1 ? 'Il piano' : 'I piani', implode('», «', $correggibili), count($correggibili) === 1 ? 'ha' : 'hanno',
                count($correggibili) === 1 ? 'è' : 'sono', count($correggibili) === 1 ? 'o' : 'i', count($correggibili) === 1 ? 'o' : 'i', $ritorno);
        }
        // Rilievo T7 del giro sulle correzioni: il perché vero del fermo (`fraseDelFermo`), non «già emesso o con incassi» per un
        // piano fermo solo per il conguaglio di un altro passaggio; «in mano ai condòmini» solo se ha quote a giornale.
        foreach ($piani->filter(fn ($p) => $p->eImmutabile()) as $piano) {
            $perche = $piano->fraseDelFermo() ?? 'non si riscrive più';
            $inMano = in_array('scrittura', $piano->ragioniDelFermo(), true) ? ': le quote sono in mano ai condòmini' : '';
            if (! $perIlPassaggio) {
                $frase .= sprintf(' Il piano «%s» %s%s, e la correzione passa da un saldo manuale di segno opposto sulla stessa gestione.', $piano->nome, $perche, $inMano);
                continue;
            }
            // Decisione 50 (rilievo W6): per l'annullamento del passaggio il saldo manuale non sblocca niente. Due strade, prima la
            // breve: il passaggio resta, e il piano che ha preso si emette così com'è (decisione 38); poi la completa.
            // Rilievo X3: la strada breve solo se i piani presi dal passaggio si emettono davvero così come sono.
            $breve = $presiDaSeguire === []
                ? ($conArretrato
                    // Rilievo L10 della Fase 1-bis della .44: nella successione con l'arretrato agli eredi le righe nel piano sono di più eredi.
                    ? sprintf(' Il passaggio può restare: i piani che ha preso si emettono così come sono, e ogni erede regola la sua parte con le righe del conguaglio e dell\'arretrato già dentro il piano «%s».', $piano->nome)
                    : sprintf(' Il passaggio può restare: i piani che ha preso si emettono così come sono, e chi entra paga i suoi giorni con la coppia già dentro il piano «%s».', $piano->nome))
                : sprintf(' Il passaggio non può restare così: %s «%s», preso da questo passaggio, deve ancora seguire un passaggio e non si emette senza ricalcolo.', count($presiDaSeguire) === 1 ? 'il piano' : 'i piani', implode('», «', $presiDaSeguire));
            $frase .= $breve . sprintf(' Per annullarlo%s, il piano «%s», che %s%s, va prima riaperto — %s —; poi riportalo in bozza, elimina il piano (il lucchetto si riapre) e %s; poi rifai il piano.',
                $presiDaSeguire === [] ? ' comunque' : '', $piano->nome, $perche, $inMano !== '' ? ', e le quote sono in mano ai condòmini' : '', implode('; ', $piano->rimediDelFermo()), $ritorno);
        }

        return $frase;
    }
}
