<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoVersionePreventivo;
use App\Helpers\DateHelper;
use App\Http\Controllers\Gestionale\PianiRate\PianoRatePrintController;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\VersionePreventivo;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mpdf\Output\Destination;

/**
 * Il preventivo «fermato» per l'assemblea (§0.4 del progetto della tappa 2), nella forma minima della versione del rendiconto: le cifre del preventivo
 * dell'esercizio del punto **in centesimi** (le voci con l'importo e le tabelle, il totale, il riparto per tabella), la loro impronta SHA-256, e i PDF
 * conservati come documenti della versione: la distinta delle voci (`pdf.gestionale.assemblea_preventivo`) e, se c'è il piano ordinario dell'esercizio, le
 * tre stampe che il piano sa già fare (le rate per condòmino, il riparto per tabella, il riparto per capitolo, `PianoRatePrintController`). Una versione
 * non si riscrive: fermare di nuovo fa la versione dopo, e quella di prima resta «sostituita». L'approvazione viene dalla delibera ({@see approva()}), e il
 * confronto con il preventivo di oggi dice se il piano è ancora quello che l'assemblea ha approvato ({@see differenzeDaOggi()}, decisione 622 (8)).
 *
 * Una riga del registro sull'assemblea: «Preventivo 2027 fermato per l'assemblea: versione 1, impronta 7f3a…».
 */
final class VersioniDelPreventivo
{
    public const SENZA_VOCI = 'Il preventivo dell\'esercizio non ha voci con un importo: scrivi il preventivo prima di fermarlo.';

    /**
     * Decisione 622 (8), «il preventivo fermato di nuovo»: dopo la convocazione l'assemblea approva il preventivo spedito, e «Ferma il preventivo» non c'è
     * più; resta solo per la versione corretta di un preventivo approvato con modifiche (R6). È la risposta proposta, in attesa di Vincenzo: per cambiarla
     * (l'ultimo fermato, con l'avviso) si toglie il rifiuto in `ferma()` e `spedita()` rende l'ultima versione.
     */
    public const DOPO_LA_CONVOCAZIONE = 'La convocazione è partita: il preventivo spedito non si ferma di nuovo. Una versione nuova si ferma solo dopo '
        . 'un\'approvazione con modifiche.';

    public function __construct(private readonly PdfService $pdf, private readonly ArchivioDocumenti $archivio) {}

    /** «Ferma il preventivo» del punto (di materia «preventivo»). */
    public function ferma(Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto, User $utente): VersionePreventivo
    {
        abort_unless((int) $punto->assemblea_id === (int) $assemblea->id, 404);
        if ($punto->materia !== MateriaPunto::PREVENTIVO) {
            throw ValidationException::withMessages(['punto' => 'Si ferma il preventivo di un punto sul preventivo.']);
        }
        if ($assemblea->stato !== StatoAssemblea::IN_PREPARAZIONE && ! self::approvatoConModifiche($punto)) {
            throw ValidationException::withMessages(['punto' => self::DOPO_LA_CONVOCAZIONE]);
        }
        $esercizio = $this->esercizio($assemblea, $punto);
        [$gestione, $piano] = $this->gestioneEPiano($esercizio);
        $cifre = $this->cifre($gestione, $piano);
        if ($cifre['voci'] === []) {
            throw ValidationException::withMessages(['punto' => self::SENZA_VOCI]);
        }
        $impronta = hash('sha256', json_encode($cifre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $anno = $esercizio->data_inizio?->format('Y') ?? (string) $esercizio->nome;
        $numero = (int) VersionePreventivo::query()->where('esercizio_id', $esercizio->id)->max('numero') + 1;
        $pdf = ['distinta' => $this->pdf->generate('pdf.gestionale.assemblea_preventivo', ['gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $assemblea->condominio, 'anno' => $anno, 'numero' => $numero, 'cifre' => $cifre, 'impronta' => $impronta,
            'fermato_il' => DateHelper::adessoUtente()->format('d/m/Y')])->Output('', Destination::STRING_RETURN)] + $this->stampeDelPiano($assemblea, $esercizio, $piano);
        $scritti = [];
        try {
            return DB::transaction(function () use ($assemblea, $punto, $esercizio, $gestione, $piano, $cifre, $impronta, $numero, $pdf, $anno, $utente, &$scritti) {
                VersionePreventivo::query()->where('esercizio_id', $esercizio->id)->where('stato', StatoVersionePreventivo::PRESENTATA->value)
                    ->update(['stato' => StatoVersionePreventivo::SOSTITUITA->value]);
                $versione = VersionePreventivo::create(['condominio_id' => $assemblea->condominio_id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione?->id,
                    'piano_rate_id' => $piano?->id, 'numero' => $numero, 'stato' => StatoVersionePreventivo::PRESENTATA->value, 'cifre' => $cifre,
                    'impronta' => $impronta, 'pdf' => [], 'punto_id' => $punto->id, 'creata_da' => $utente->id]);
                $documenti = [];
                foreach ($pdf as $parte => $contenuto) {
                    $percorso = $this->archivio->salvaContenuto($contenuto, 'pdf', ArchivioDocumenti::CARTELLA . '/' . $assemblea->condominio_id . '/preventivo');
                    $scritti[] = $percorso;
                    $d = $versione->documenti()->create(['name' => 'preventivo-' . $anno . '-v' . $numero . '-' . $parte . '.pdf',
                        'description' => 'Preventivo ' . $anno . ', versione ' . $numero . ': ' . self::nomeDellaParte($parte), 'path' => $percorso,
                        'mime_type' => 'application/pdf', 'file_size' => strlen($contenuto), 'created_by' => $utente->id, 'is_published' => false, 'is_approved' => true]);
                    $documenti[] = ['parte' => $parte, 'documento_id' => $d->id, 'impronta' => hash('sha256', $contenuto)];
                }
                RegistroModifiche::durante(GestiDelRegistro::preventivoFermato($assemblea, $versione, $anno), fn () => $versione->update(['pdf' => $documenti]));

                return $versione->refresh();
            });
        } catch (\Throwable $e) {
            foreach ($scritti as $p) {
                $this->archivio->elimina($p);
            }

            throw $e;
        }
    }

    /**
     * L'approvazione dalla delibera (il seguito delle delibere): la versione presentata diventa «approvata», con il collegamento `approvazione`; una approvata
     * prima nello stesso esercizio resta «sostituita» (l'assemblea che corregge fa una delibera nuova). Si approva anche la versione spedita che una fermata
     * dopo la spedizione ha messo in «sostituita»: l'assemblea ha visto quella; chi chiama la sceglie con {@see spedita()}.
     */
    public static function approva(VersionePreventivo $versione, \App\Models\Assemblee\Delibera $delibera, User $utente): void
    {
        if (! in_array($versione->stato, [StatoVersionePreventivo::PRESENTATA, StatoVersionePreventivo::SOSTITUITA], true)) {
            throw ValidationException::withMessages(['versione' => 'Si approva una versione presentata: la versione ' . $versione->numero . ' è '
                . mb_strtolower((string) $versione->stato?->parola()) . '.']);
        }
        DB::transaction(function () use ($versione, $delibera, $utente) {
            foreach (VersionePreventivo::query()->where('esercizio_id', $versione->esercizio_id)->where('stato', StatoVersionePreventivo::APPROVATA->value)->get() as $prima) {
                $prima->update(['stato' => StatoVersionePreventivo::SOSTITUITA->value]);
            }
            $versione->update(['stato' => StatoVersionePreventivo::APPROVATA->value]);
            \App\Services\Assemblee\CollegamentiDelleDelibere::collega($versione, \App\Enums\Assemblee\RuoloCollegamento::APPROVAZIONE->value, $delibera, null,
                $utente);
        });
    }

    /**
     * Il piano contro il preventivo fermato e spedito (decisione 622 (8)): **ciò che il piano chiede**, in centesimi, contro ciò che l'assemblea ha approvato.
     *
     * - **Le voci**: per ogni voce l'importo che il piano chiede (la copia della voce nel piano, `piano_rate_capitoli.importo`, che non segue la voce dopo; senza
     *   copia la voce di oggi) contro quello fermato.
     * - **Il riparto**: le tabelle di ogni voce con il coefficiente, oggi contro quelle fermate (art. 1135 n. 2 c.c., «e della relativa ripartizione»).
     * - **Le rate**: se il piano ha le rate generate, devono tornare con le voci del piano (lo stato di allineamento del piano, tolleranza zero): rate generate
     *   su un'altra cifra sono un piano diverso anche se le voci sono tornate come prima.
     *
     * Vuoto (`uguale`) se il piano chiede ciò che l'assemblea ha approvato. Lo scadenziario per condòmino non si rifà: si confrontano le voci e le rate.
     *
     * @return array{uguale: bool, fermato: int, oggi: int, voci: list<array{nome: string, prima: int, dopo: int}>, tabelle: list<array{nome: string, prima: list<array{nome: string, coefficiente: float}>, dopo: list<array{nome: string, coefficiente: float}>}>, rate: ?array{chiede: int, generate_il: ?string}}
     */
    public function differenzeDaOggi(VersionePreventivo $versione, ?PianoRate $piano = null): array
    {
        $gestione = $versione->gestione_id === null ? null : Gestione::query()->find($versione->gestione_id);
        $piano ??= $versione->piano_rate_id === null ? null : PianoRate::query()->find($versione->piano_rate_id);
        $oggi = $this->cifre($gestione, $piano);
        $fermate = (array) $versione->cifre;
        $prima = collect((array) ($fermate['voci'] ?? []))->keyBy(fn ($v) => (int) $v['conto_id']);
        $dopo = collect($oggi['voci'])->keyBy(fn ($v) => (int) $v['conto_id']);
        // La copia della voce nel piano: è quella che il piano chiede, anche se la voce cambia dopo («Snapshot Puro» di SyncOrphanChaptersAction).
        $nelPiano = $piano === null ? [] : $piano->capitoli()->get()->filter(fn ($c) => $c->pivot->importo !== null)
            ->mapWithKeys(fn ($c) => [(int) $c->id => (int) $c->pivot->importo])->all();
        $voci = [];
        $tabelle = [];
        $chiede = 0;
        foreach ($prima->keys()->merge($dopo->keys())->merge(array_keys($nelPiano))->unique()->sort()->values() as $id) {
            $nome = (string) ($dopo[$id]['nome'] ?? $prima[$id]['nome'] ?? Conto::query()->whereKey($id)->value('nome') ?? '');
            $a = (int) ($prima[$id]['importo'] ?? 0);
            $b = array_key_exists($id, $nelPiano) ? $nelPiano[$id] : (int) ($dopo[$id]['importo'] ?? 0);
            $chiede += $b;
            if ($a !== $b) {
                $voci[] = ['nome' => $nome, 'prima' => $a, 'dopo' => $b];
            }
            if (isset($prima[$id], $dopo[$id]) && self::tabelleOrdinate((array) ($prima[$id]['tabelle'] ?? [])) !== self::tabelleOrdinate((array) ($dopo[$id]['tabelle'] ?? []))) {
                $tabelle[] = ['nome' => $nome, 'prima' => self::tabelleOrdinate((array) ($prima[$id]['tabelle'] ?? [])),
                    'dopo' => self::tabelleOrdinate((array) ($dopo[$id]['tabelle'] ?? []))];
            }
        }
        $rate = null;
        if ($piano !== null && \Illuminate\Support\Facades\DB::table('rate')->where('piano_rate_id', $piano->id)->exists()) {
            $allineamento = app(\App\Services\PianoRateQuoteService::class);
            if ($allineamento->statoAllineamento($piano) === \App\Services\PianoRateQuoteService::DISALLINEATO) {
                $generate = \Illuminate\Support\Facades\DB::table('rate')->where('piano_rate_id', $piano->id)->max('created_at');
                $rate = ['chiede' => $allineamento->totalePuroGeneratoCents($piano), 'generate_il' => $generate === null ? null
                    : \Illuminate\Support\Carbon::parse($generate)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d')];
            }
        }
        $fermato = (int) ($fermate['totale'] ?? 0);

        return ['uguale' => $voci === [] && $tabelle === [] && $rate === null && $fermato === $chiede, 'fermato' => $fermato,
            'oggi' => $rate['chiede'] ?? $chiede, 'voci' => $voci, 'tabelle' => $tabelle, 'rate' => $rate];
    }

    /** Le tabelle di una voce in un ordine solo, con il coefficiente a due decimali: due riparti uguali danno lo stesso elenco. @return list<array{nome: string, coefficiente: float}> */
    public static function tabelleOrdinate(array $tabelle): array
    {
        $elenco = array_map(fn ($t) => ['nome' => (string) ($t['nome'] ?? ''), 'coefficiente' => round((float) ($t['coefficiente'] ?? 100), 2)], $tabelle);
        usort($elenco, fn ($a, $b) => [$a['nome'], $a['coefficiente']] <=> [$b['nome'], $b['coefficiente']]);

        return array_values($elenco);
    }

    /** La versione fermata di un punto (l'ultima non sostituita), o null. */
    public static function delPunto(PuntoDellOrdineDelGiorno $punto): ?VersionePreventivo
    {
        return VersionePreventivo::query()->where('punto_id', $punto->id)->where('stato', '!=', StatoVersionePreventivo::SOSTITUITA->value)->orderByDesc('numero')->first();
    }

    /**
     * La versione **spedita** con la convocazione: l'ultima del punto fermata entro `$entro` (la prima spedizione, o la fine del giorno della delibera),
     * qualunque sia il suo stato: una fermata dopo l'ha messa in «sostituita», ma l'assemblea ha visto questa. Senza `$entro`, l'ultima non sostituita.
     */
    public static function spedita(PuntoDellOrdineDelGiorno $punto, ?\Carbon\CarbonInterface $entro): ?VersionePreventivo
    {
        if ($entro === null) {
            return self::delPunto($punto);
        }

        return VersionePreventivo::query()->where('punto_id', $punto->id)->where('created_at', '<=', $entro)->orderByDesc('numero')->first();
    }

    /** R6: l'ultima versione presentata del punto fermata dopo `$dopo` (la fine del giorno della delibera approvata con modifiche), o null. */
    public static function fermataDopo(PuntoDellOrdineDelGiorno $punto, ?\Carbon\CarbonInterface $dopo): ?VersionePreventivo
    {
        return $dopo === null ? null : VersionePreventivo::query()->where('punto_id', $punto->id)->where('stato', StatoVersionePreventivo::PRESENTATA->value)
            ->where('created_at', '>', $dopo)->orderByDesc('numero')->first();
    }

    /** Il punto ha una delibera approvata con modifiche: la versione corretta si ferma anche dopo la convocazione (R6). */
    public static function approvatoConModifiche(PuntoDellOrdineDelGiorno $punto): bool
    {
        return \App\Models\Assemblee\Delibera::query()->where('punto_id', $punto->id)
            ->where('esito', \App\Enums\Assemblee\EsitoDelibera::APPROVATA_CON_MODIFICHE->value)->exists();
    }

    public static function nomeDellaParte(string $parte): string
    {
        return ['distinta' => 'la distinta delle voci', 'scadenziario' => 'le rate per condòmino', 'riparto_tabelle' => 'il riparto per tabella',
            'riparto_capitoli' => 'il riparto per capitolo'][$parte] ?? $parte;
    }

    /** L'esercizio del punto; senza, quello che comincia dopo l'esercizio aperto, o l'ultimo del condominio. */
    private function esercizio(Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto): Esercizio
    {
        if ($punto->esercizio_id !== null) {
            $e = Esercizio::query()->whereKey($punto->esercizio_id)->where('condominio_id', $assemblea->condominio_id)->first();
            if ($e !== null) {
                return $e;
            }
        }

        return Esercizio::query()->where('condominio_id', $assemblea->condominio_id)->orderByDesc('data_inizio')->first()
            ?? throw ValidationException::withMessages(['punto' => 'Il condominio non ha un esercizio per il preventivo.']);
    }

    /** La gestione ordinaria dell'esercizio e il suo piano rate ordinario (la bozza, se c'è). @return array{0: ?Gestione, 1: ?PianoRate} */
    private function gestioneEPiano(Esercizio $esercizio): array
    {
        $gestione = $esercizio->gestioni()->where('gestioni.tipo', 'ordinaria')->orderBy('gestioni.id')->first();
        $piano = $gestione === null ? null : PianoRate::query()->where('gestione_id', $gestione->id)->where('esercizio_id', $esercizio->id)
            ->where('tipo', 'ordinario')->orderByRaw("CASE stato WHEN 'bozza' THEN 0 ELSE 1 END")->orderByDesc('id')->first();

        return [$gestione, $piano];
    }

    /**
     * Le cifre: le voci di spesa del piano dei conti della gestione con un importo (centesimi), le tabelle con il coefficiente, il totale e il riparto per
     * tabella (l'importo per il coefficiente, arrotondato al centesimo per voce).
     *
     * @return array{voci: list<array<string, mixed>>, totale: int, per_tabella: list<array{tabella: string, importo: int}>, piano_rate_id: ?int, gestione: ?string}
     */
    private function cifre(?Gestione $gestione, ?PianoRate $piano): array
    {
        $voci = [];
        $perTabella = [];
        if ($gestione !== null && ($pc = $gestione->pianoConto()->first()) !== null) {
            $conti = Conto::query()->where('piano_conto_id', $pc->id)->where('tipo', 'spesa')->where('importo', '>', 0)
                ->where(fn ($q) => $q->whereNull('is_tecnico')->orWhere('is_tecnico', false))->with('tabelle')->orderBy('id')->get();
            foreach ($conti as $c) {
                $tabelle = $c->tabelle->map(fn ($t) => ['nome' => (string) $t->nome, 'coefficiente' => (float) ($t->pivot->coefficiente ?? 100)])->values()->all();
                $voci[] = ['conto_id' => (int) $c->id, 'nome' => (string) $c->nome, 'importo' => (int) $c->importo, 'tabelle' => $tabelle];
                foreach ($tabelle as $t) {
                    $perTabella[$t['nome']] = ($perTabella[$t['nome']] ?? 0) + (int) round(((int) $c->importo) * $t['coefficiente'] / 100);
                }
            }
        }

        return [
            'voci' => $voci,
            'totale' => array_sum(array_column($voci, 'importo')),
            'per_tabella' => array_map(fn ($nome, $importo) => ['tabella' => (string) $nome, 'importo' => (int) $importo], array_keys($perTabella), $perTabella),
            'piano_rate_id' => $piano?->id,
            'gestione' => $gestione?->nome,
        ];
    }

    /** Le tre stampe del piano, quando c'è; una che non si fa resta fuori, e la distinta basta. @return array<string, string> */
    private function stampeDelPiano(Assemblea $assemblea, Esercizio $esercizio, ?PianoRate $piano): array
    {
        if ($piano === null) {
            return [];
        }
        $stampe = [];
        foreach (['scadenziario' => 'scadenziario', 'riparto_tabelle' => 'ripartoTabelle', 'riparto_capitoli' => 'ripartoCapitoli'] as $parte => $metodo) {
            try {
                $r = app()->call([app(PianoRatePrintController::class), $metodo], ['request' => new Request(['modalita' => 'anagrafica']),
                    'condominio' => $assemblea->condominio, 'esercizio' => $esercizio, 'pianoRate' => $piano]);
                $contenuto = method_exists($r, 'getContent') ? (string) $r->getContent() : '';
                if (str_starts_with($contenuto, '%PDF')) {
                    $stampe[$parte] = $contenuto;
                }
            } catch (\Throwable) {
                // La stampa del piano non si fa (un piano senza rate generate): la distinta resta, con le cifre.
            }
        }

        return $stampe;
    }
}
