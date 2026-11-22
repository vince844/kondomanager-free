<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Lettere;

use App\Exceptions\Gestionale\LetteraNonEmettibileException;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Debiti\AccordiRientro;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\ConsegnaDellaPec;
use App\Services\Posta\RicevutaDellInvio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Il registro delle lettere di una persona in un condominio: che cosa le è stato scritto, quando, come, da chi; il totale di allora e il
 * residuo di oggi delle stesse rate; il PDF conservato e la sua impronta; la spedizione registrata a mano e le prove.
 *
 * - **Una lettera** sono le righe con lo stesso `dati.lettera`: una per una lettera stampata o scaricata, una per indirizzo se è andata per
 *   email. «L'ultima lettera» conta le lettere partite, stampate o spedite ({@see RegistroInvio::STATI_LETTERA_USCITA}); «mai scritto» e
 *   «nessuna lettera negli ultimi N giorni» solo quelle partite o spedite ({@see RegistroInvio::STATI_LETTERA_ARRIVATA}): una stampata o
 *   scaricata senza la spedizione registrata no. Una lettera in coda o non partita si mostra a parte.
 * - **La spedizione** (diffida, messa in mora, e qualunque lettera stampata o scaricata): canale, data, numero o estremi, nota. La riga passa
 *   da «stampata» a «spedita»; ogni fatto dopo è un evento in `dati.eventi`, con la data e chi l'ha registrato, e niente si cancella. Una
 *   spedizione registrata con un errore si corregge ({@see correggiSpedizione()}): la riga mostra l'ultima, l'evento tiene quella di prima.
 * - **La prova** è un documento della riga (non entra nell'archivio né nel portale). Si toglie finché l'esercizio che contiene la data della
 *   lettera non è chiuso: se quell'esercizio non c'è ancora, si toglie; l'evento resta.
 * - **La PEC** (decisioni 569, 577 e 588): la riga `pec` di una lettera partita dalla casella dello studio prende la ricevuta di consegna del gestore
 *   ({@see registraLaRicevuta()}): l'esito «consegnata» con il giorno e l'ora del gestore, il file archiviato come prova della riga, l'evento. Quando la
 *   ricevuta non si legge (un PDF, un file senza il `daticert.xml`) il giorno e l'ora si scrivono a mano, con il file come prova. La prova da cui viene la
 *   consegna non si toglie: un esito senza la sua prova direbbe una consegna senza prova. Le parole dello stato le dà {@see ConsegnaDellaPec}.
 * - **L'avviso di mancata consegna** del gestore, trascinato sulla riga, dà l'esito «non consegnata» con il file come prova: la lettera non conta fra le
 *   arrivate (per le liste e per il passo vale come una PEC non partita) e il termine non corre; la riga offre di mandarla di nuovo per PEC o di stamparla.
 */
final class RegistroLettere
{
    /** I canali della spedizione, con il nome che l'amministratore legge. Un corriere o un ufficiale giudiziario è «altro», con la nota. */
    public const CANALI_SPEDIZIONE = [
        'raccomandata' => 'raccomandata A/R',
        'pec'          => 'PEC mandata fuori dal programma',
        'a_mano'       => 'a mano',
        'posta'        => 'posta ordinaria',
        'altro'        => 'altro',
    ];

    /** I file di una prova: le scansioni, le ricevute della PEC (`.eml`) e i file firmati (`.p7m`). */
    public const ESTENSIONI_PROVA = ['pdf', 'jpg', 'jpeg', 'png', 'eml', 'p7m'];

    public const MB_PROVA = 10.0;

    public const SOLO_STAMPATE = 'Si registra la spedizione di una lettera stampata o scaricata, non ancora spedita: questa non lo è.';
    public const GIA_SPEDITA = 'Questa lettera ha già la spedizione registrata. Per correggerla: «Correggi la spedizione» nel registro della persona, nella '
        . 'pagina del suo estratto conto.';
    public const SOLO_SPEDITE = 'Si corregge la spedizione di una lettera già spedita: questa non ha una spedizione registrata.';
    public const PROVA_FERMA = 'L\'esercizio che contiene la data della lettera è chiuso: la prova resta.';
    public const PROVA_SU_USCITE = 'Si allega una prova a una lettera stampata, spedita o partita.';
    public const PROVA_DELLA_CONSEGNA = 'È la prova della consegna della PEC: resta con la lettera.';
    public const SOLO_PEC_PARTITE = 'La ricevuta di consegna si trascina su una lettera partita per PEC dalla casella dello studio.';
    public const RICEVUTA_NON_LETTA = 'Il programma non legge il giorno e l\'ora da questo file: non è una ricevuta .eml con i dati del gestore. Controlla qui sotto il '
        . 'giorno e l\'ora della consegna, proposti dall\'invio, e registrala con il file come prova.';
    public const CONSEGNA_GIA_REGISTRATA = 'Questa PEC ha già la consegna registrata: per cambiarla scrivi perché la correggi.';
    public const CONFERMA_DELLA_RICEVUTA = 'La ricevuta del gestore conferma la consegna scritta a mano.';
    public const MANCATA_CONSEGNA_GIA_REGISTRATA = 'Questa PEC ha già l\'esito registrato: per cambiarlo scrivi perché lo correggi.';

    /** Le colonne di una riga che servono a dire la lettera nell'elenco ({@see lettera()}): la spedizione, la PEC e la sua consegna. */
    private const COLONNE_DELLA_LETTERA = ['id', 'anagrafica_id', 'tipo', 'canale', 'stato', 'destinatario', 'emesso_il', 'esito', 'ricevuta_il', 'dati->lettera as lettera',
        'dati->formato as formato', 'dati->pec_e_carta as pec_e_carta', 'dati->consegna->a_mano as consegna_a_mano', 'dati->spedizione->canale as spedizione_canale',
        'dati->spedizione->data as spedizione_data', 'dati->spedizione->numero as spedizione_numero'];

    /** L'ordine degli stati di una lettera con più righe: vale il più avanti. */
    private const RANGO = [RegistroInvio::STATO_NON_PARTITA => 0, RegistroInvio::STATO_IN_CODA => 1, RegistroInvio::STATO_STAMPATA => 2,
        RegistroInvio::STATO_INVIATA => 3, RegistroInvio::STATO_SPEDITA => 4];

    public function __construct(private readonly LetturaDebiti $lettura, private readonly ArchivioDocumenti $archivio, private readonly AccordiRientro $accordi) {}

    /** Le chiavi di `per_tipo`: la diffida e la messa in mora stanno insieme (la più recente delle due), il sollecito, il promemoria. */
    public const PER_TIPO = ['diffida' => ['diffida', 'messa_in_mora'], 'sollecito' => ['sollecito'], 'promemoria' => ['promemoria']];

    /**
     * L'ultima lettera di ogni persona alla data (quelle partite, stampate o spedite) e, se dopo ce n'è una in coda o non partita, quella; e a
     * parte l'ultima che conta per i filtri dell'elenco (`conta`): partita o spedita, non solo stampata o scaricata.
     *
     * `per_tipo`: l'ultima lettera di ogni tipo, in qualunque stato (stampata, partita, spedita, in coda, non partita), dalle righe già lette: una diffida
     * seguita da un sollecito resta nella sua chiave. Ognuna porta in più il termine (`termine`, `termine_forma`, `termine_data`) e le rate che chiedeva
     * (`righe`, le chiavi «rata|unità»), lette con una seconda lettura solo per queste lettere.
     *
     * @param list<int> $persone
     * @return array<int, array{ultima: ?array<string,mixed>, in_sospeso: ?array<string,mixed>, conta: ?array<string,mixed>, per_tipo: array<string, ?array<string,mixed>>}>
     */
    public function ultimePerPersone(int $condominioId, array $persone, string $alla): array
    {
        if ($persone === []) {
            return [];
        }
        $fine = Carbon::parse($alla, self::fuso())->addDay()->startOfDay()->utc();
        $righe = RegistroInvio::query()
            ->where('condominio_id', $condominioId)
            ->whereIn('anagrafica_id', $persone)
            ->whereIn('tipo', RegistroInvio::TIPI_LETTERE)
            ->where('emesso_il', '<', $fine)
            ->orderBy('emesso_il')->orderBy('id')
            ->get(self::COLONNE_DELLA_LETTERA);

        $esito = [];
        // La prima riga di ogni lettera (una per indirizzo): da lì si leggono il termine e le rate delle lettere di `per_tipo`.
        $primaDi = [];
        foreach ($righe->groupBy('anagrafica_id') as $persona => $sue) {
            $lettere = $sue->groupBy(fn ($r) => (string) ($r->lettera ?? 'riga-' . $r->id))->map(function (Collection $g) use (&$primaDi) {
                $l = self::lettera($g);
                $primaDi[$l['id']] = (int) $g->min('id');

                return $l;
            })->sortBy(fn ($l) => [$l['quando'], $l['id']])->values();
            $uscite = $lettere->filter(fn ($l) => in_array($l['stato'], RegistroInvio::STATI_LETTERA_USCITA, true));
            $ultima = $uscite->last();
            $dopo = $lettere->filter(fn ($l) => ! in_array($l['stato'], RegistroInvio::STATI_LETTERA_USCITA, true) && ($ultima === null || $l['id'] > $ultima['id']))->last();
            // Quella che conta è la più recente per il giorno dei filtri (per una spedita il giorno della spedizione), non l'ultima emessa: una
            // diffida stampata prima e spedita dopo un promemoria è la lettera più recente.
            $conta = $lettere->filter(fn ($l) => in_array($l['stato'], RegistroInvio::STATI_LETTERA_ARRIVATA, true))->sortBy('data_dei_filtri')->last();
            $perTipo = [];
            foreach (self::PER_TIPO as $chiave => $tipi) {
                $perTipo[$chiave] = $lettere->filter(fn ($l) => in_array($l['tipo'], $tipi, true))->last();
            }
            $esito[(int) $persona] = ['ultima' => $ultima, 'in_sospeso' => $dopo, 'conta' => $conta, 'per_tipo' => $perTipo];
        }

        // Il termine e le rate delle sole lettere di `per_tipo`: una lettura piccola, con la prima riga di ogni lettera.
        $prime = [];
        foreach ($esito as $persona => $e) {
            foreach ($e['per_tipo'] as $chiave => $l) {
                if ($l !== null) {
                    $prime[$primaDi[$l['id']]] = [$persona, $chiave];
                }
            }
        }
        if ($prime !== []) {
            $dati = RegistroInvio::query()->whereIn('id', array_keys($prime))
                ->get(['id', 'dati->righe as righe', 'dati->termine as termine', 'dati->termine_forma as termine_forma', 'dati->termine_data as termine_data']);
            foreach ($dati as $r) {
                [$persona, $chiave] = $prime[(int) $r->id];
                $righe = is_string($r->righe) ? json_decode($r->righe, true) : (array) ($r->righe ?? []);
                $esito[$persona]['per_tipo'][$chiave] += [
                    'termine' => self::testoJson($r->termine) === null ? null : (int) self::testoJson($r->termine),
                    'termine_forma' => self::testoJson($r->termine_forma),
                    'termine_data' => self::testoJson($r->termine_data),
                    'righe' => array_values(array_map(fn ($x) => AccordiRientro::chiave($x['rata_id'] ?? null, $x['immobile_id'] ?? null), is_array($righe) ? $righe : [])),
                ];
            }
        }

        return $esito;
    }

    /**
     * Le lettere di un condominio arrivate in un periodo, per tipo: partite per email o spedite (come per «mai scritto»: una lettera solo stampata o
     * scaricata non conta, il programma non sa se è stata mandata), con il giorno che contano i filtri (per una spedita, quello della spedizione).
     * Le righe della stessa lettera, una per indirizzo, sono una lettera.
     *
     * @return array<string, int>
     */
    public function contaArrivate(int $condominioId, string $dal, string $al): array
    {
        $inizio = Carbon::parse($dal, self::fuso())->startOfDay()->subDays(400)->utc();
        $fine = Carbon::parse($al, self::fuso())->addDay()->startOfDay()->utc();
        $righe = RegistroInvio::query()->where('condominio_id', $condominioId)->whereIn('tipo', RegistroInvio::TIPI_LETTERE)
            ->where('emesso_il', '>=', $inizio)->where('emesso_il', '<', $fine)->orderBy('emesso_il')->orderBy('id')
            ->get(self::COLONNE_DELLA_LETTERA);
        $conti = [];
        foreach ($righe->groupBy(fn ($r) => (string) ($r->lettera ?? 'riga-'.$r->id)) as $gruppo) {
            $l = self::lettera($gruppo);
            if (in_array($l['stato'], RegistroInvio::STATI_LETTERA_ARRIVATA, true) && $l['data_dei_filtri'] >= $dal && $l['data_dei_filtri'] <= $al) {
                $conti[$l['tipo']] = ($conti[$l['tipo']] ?? 0) + 1;
            }
        }

        return $conti;
    }

    /** I giorni fra la data di una lettera e la data dell'elenco, nel calendario dell'amministratore. */
    public static function giorniDa(string $data, string $alla): int
    {
        return (int) CarbonImmutable::parse($data, 'UTC')->diffInDays(CarbonImmutable::parse($alla, 'UTC'), false);
    }

    /**
     * Il registro di una persona in un condominio, dalla lettera più recente: per ognuna il tipo, quando, chi, le righe (canale, destinatario,
     * stato, errore, spedizione), il totale di allora e il residuo di oggi delle stesse rate (dalla lettura dei debiti), il PDF conservato con
     * l'impronta verificata, le prove e gli eventi.
     *
     * @return list<array<string, mixed>>
     */
    public function perPersona(Condominio $condominio, Anagrafica $persona): array
    {
        $righe = RegistroInvio::with('emessoDa:id,name')
            ->where('condominio_id', $condominio->id)
            ->where('anagrafica_id', $persona->id)
            ->whereIn('tipo', RegistroInvio::TIPI_LETTERE)
            ->orderBy('id')
            ->get();
        if ($righe->isEmpty()) {
            return [];
        }

        // Decisione 493 (12): con il debito ereditato al suo posto, come la lettera l'ha letto da «Rate scadute».
        $lettura = app(\App\Services\Gestionale\Accessori\DebitoEreditato::class)->rateConIlDebitoEreditato((int) $condominio->id, (int) $persona->id,
            $this->lettura->perPersona((int) $condominio->id, (int) $persona->id)['rate']);
        $oggi = collect($lettura)->mapWithKeys(fn ($r) => [$r['rata_id'] . '|' . ($r['immobile_id'] ?? '') => (int) $r['residuo']]);
        // La parte di ogni rata che oggi un accordo di rientro in corso lascia fuori dalle lettere.
        $inAccordoOggi = $this->accordi->fuoriDalleLettere((int) $condominio->id, (int) $persona->id, $lettura, DateHelper::oggiUtente());
        $documenti = Documento::where('documentable_type', RegistroInvio::class)->whereIn('documentable_id', $righe->pluck('id'))->get()->keyBy('id');
        $deiDocumenti = $documenti->groupBy('documentable_id');
        $casella = $righe->contains(fn (RegistroInvio $r) => $r->canale === RegistroInvio::CANALE_PEC) ? app(CasellaPec::class)->configurazione() : null;

        $lettere = [];
        foreach ($righe->groupBy(fn (RegistroInvio $r) => (string) ($r->dati['lettera'] ?? 'riga-' . $r->id)) as $chiave => $sue) {
            $prima = $sue->first();
            $dati = (array) $prima->dati;
            $conservato = isset($dati['documento_id']) ? $documenti->get((int) $dati['documento_id']) : null;
            // I PDF conservati di tutte le righe della lettera non sono prove: con «PEC e carta», prima la stampa, anche la PEC ha il suo.
            $conservati = $sue->map(fn (RegistroInvio $r) => (int) ($r->dati['documento_id'] ?? 0))->filter()->all();
            $prove = $sue->flatMap(fn (RegistroInvio $r) => ($deiDocumenti[$r->id] ?? collect())->filter(fn (Documento $d) => ! in_array((int) $d->id, $conservati, true))
                ->map(fn (Documento $d) => ['id' => $d->id, 'invio_id' => $r->id, 'nome' => $d->name, 'aggiunta_il' => $d->created_at?->copy()->setTimezone(self::fuso())->format('Y-m-d'),
                    // La ricevuta di consegna della PEC, l'avviso di mancata consegna, o la prova della consegna scritta a mano: non si toglie.
                    'della_consegna' => in_array((int) $d->id, self::documentiDellaConsegna($r), true),
                    // La prova della consegna scritta a mano (una foto, un PDF) e l'avviso di mancata consegna: la pagina li dice per quello che sono.
                    'consegna_a_mano' => in_array((int) $d->id, self::documentiDellaConsegna($r, 'consegna_a_mano'), true),
                    'mancata_consegna' => in_array((int) $d->id, self::documentiDellaConsegna($r, 'mancata_consegna'), true),
                    'rimovibile' => $this->provaRimovibile($r) && ! in_array((int) $d->id, self::documentiDellaConsegna($r), true)]))->values()->all();
            $residuoOggi = 0;
            foreach ((array) ($dati['righe'] ?? []) as $r) {
                // Di una rata coperta in parte da un accordo, la lettera chiedeva la parte fuori dall'accordo: il residuo di oggi si confronta
                // con quella, senza la parte che era in accordo, finché oggi l'accordo la lascia ancora fuori (chiuso l'accordo, conta tutta).
                $k = ($r['rata_id'] ?? '') . '|' . ($r['immobile_id'] ?? '');
                $residuoOggi += max(0, (int) ($oggi[$k] ?? 0) - min((int) ($r['in_accordo'] ?? 0), $inAccordoOggi[$k] ?? 0));
            }
            $lettere[] = [
                'lettera'      => $chiave,
                'tipo'         => $prima->tipo,
                'emessa_il'    => $prima->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d'),
                'ora'          => $prima->emesso_il->copy()->setTimezone(self::fuso())->format('H:i'),
                'emesso_da'    => $prima->emessoDa?->name,
                'totale'       => (int) ($dati['totale'] ?? 0),
                'residuo_oggi' => $residuoOggi,
                'alla'         => $dati['alla'] ?? null,
                'termine'      => $dati['termine'] ?? null,
                // Con la consegna della PEC provata, il termine «N giorni dal ricevimento» si conta da lì (la prima consegna, con «PEC e carta»).
                'termine_dalla_consegna' => self::termineDallaConsegna($dati, $sue),
                'stato'        => self::statoDiLettera($sue->map(fn (RegistroInvio $r) => self::statoDellaRiga($r))->all()),
                'righe'        => $sue->map(fn (RegistroInvio $r) => [
                    'id' => $r->id, 'canale' => $r->canale, 'formato' => $r->dati['formato'] ?? null, 'destinatario' => $r->destinatario, 'stato' => $r->stato,
                    'quando' => $r->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d H:i'),
                    'errore' => \App\Services\Gestionale\Ricevute\ErroreDiPosta::frase($r->errore),
                    'spedizione' => $r->dati['spedizione'] ?? null,
                    'spedibile' => $r->canale === RegistroInvio::CANALE_STAMPA && $r->stato === RegistroInvio::STATO_STAMPATA,
                    // «Invia di nuovo»: una lettera per email partita o non partita, finché dopo non ce n'è un'altra dello stesso tipo uscita o in coda; non
                    // quella andata per email alla PEC, che oggi parte dalla casella PEC dello studio.
                    'rimandabile' => $r->canale === RegistroInvio::CANALE_EMAIL && $r->stato !== RegistroInvio::STATO_IN_CODA && ! in_array($r->tipo, EmissioneLettere::TIPI_SENZA_EMAIL, true)
                        && ! self::superata($r, $righe) && ! self::perEmailAllaPec($r),
                    'pec' => $r->canale === RegistroInvio::CANALE_PEC ? self::statoDellaPec($r, $righe, $casella, $sue) : null,
                ])->values()->all(),
                'pdf'          => $conservato === null ? null : ['invio_id' => $prima->id, 'nome' => $conservato->name, 'impronta' => $prima->impronta,
                    'verificato' => $this->impronta($conservato) === $prima->impronta],
                'prove'        => $prove,
                'eventi'       => $sue->flatMap(fn (RegistroInvio $r) => (array) ($r->dati['eventi'] ?? []))->sortBy('il')->values()->all(),
            ];
        }

        return array_reverse($lettere);
    }

    /**
     * «Registra la spedizione», una o in blocco: le righe (lettere stampate o scaricate, non ancora spedite, di questo condominio), il canale, la
     * data (non dopo oggi), il numero di ognuna se c'è, una nota. Tutte o nessuna. `$tipi`: le lettere di serie; la convocazione di un'assemblea passa
     * `[RegistroInvio::TIPO_CONVOCAZIONE]` (la spedizione della raccomandata con il suo numero, come le lettere).
     *
     * @param list<int> $invii
     * @param array<int|string, mixed> $numeri
     */
    public function registraSpedizione(Condominio $condominio, array $invii, string $canale, string $data, array $numeri, ?string $nota, ?User $utente,
        array $tipi = RegistroInvio::TIPI_LETTERE): int
    {
        $righe = $this->righeDelCondominio($condominio, $invii, $tipi);
        foreach ($righe as $r) {
            if ($r->canale === RegistroInvio::CANALE_STAMPA && $r->stato === RegistroInvio::STATO_SPEDITA) {
                throw new LetteraNonEmettibileException(self::GIA_SPEDITA);
            }
            if ($r->canale !== RegistroInvio::CANALE_STAMPA || $r->stato !== RegistroInvio::STATO_STAMPATA) {
                throw new LetteraNonEmettibileException(self::SOLO_STAMPATE);
            }
            self::nonPrimaDellaLettera($r, $data);
        }

        DB::transaction(function () use ($righe, $canale, $data, $numeri, $nota, $utente) {
            foreach ($righe as $r) {
                $spedizione = self::spedizione($canale, $data, $numeri[$r->id] ?? null, $nota);
                $dati = (array) $r->dati;
                $dati['spedizione'] = $spedizione;
                $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento('spedizione', $utente) + $spedizione];
                $r->update(['stato' => RegistroInvio::STATO_SPEDITA, 'dati' => $dati]);
            }
        });

        return $righe->count();
    }

    /**
     * «Correggi la spedizione» di una lettera spedita: il canale, la data, il numero o gli estremi, la nota nuovi. Niente si cancella: la riga
     * mostra la spedizione nuova, e l'evento `spedizione_corretta` tiene quella di prima, chi l'ha corretta e quando. Le prove restano.
     */
    public function correggiSpedizione(RegistroInvio $riga, string $canale, string $data, mixed $numero, ?string $nota, ?User $utente): void
    {
        if ($riga->canale !== RegistroInvio::CANALE_STAMPA || $riga->stato !== RegistroInvio::STATO_SPEDITA) {
            throw new LetteraNonEmettibileException(self::SOLO_SPEDITE);
        }
        self::nonPrimaDellaLettera($riga, $data);
        DB::transaction(function () use ($riga, $canale, $data, $numero, $nota, $utente) {
            $riga = RegistroInvio::whereKey($riga->id)->lockForUpdate()->first();
            $dati = (array) $riga->dati;
            $prima = (array) ($dati['spedizione'] ?? []);
            $dati['spedizione'] = self::spedizione($canale, $data, $numero, $nota);
            $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento('spedizione_corretta', $utente) + $dati['spedizione'] + ['prima' => $prima]];
            $riga->update(['dati' => $dati]);
        });
    }

    /** I dati di una spedizione: il canale, la data, il numero o gli estremi (al massimo 100 caratteri), la nota; i vuoti non ci sono. */
    private static function spedizione(string $canale, string $data, mixed $numero, ?string $nota): array
    {
        $numero = is_scalar($numero) && trim((string) $numero) !== '' ? mb_substr(trim((string) $numero), 0, 100) : null;

        return array_filter(['canale' => $canale, 'data' => $data, 'numero' => $numero, 'nota' => $nota], fn ($v) => $v !== null && $v !== '');
    }

    /** Una lettera non si spedisce prima di esserci. */
    private static function nonPrimaDellaLettera(RegistroInvio $riga, string $data): void
    {
        $giorno = EmissioneLettere::giornoDellaLettera($riga);
        if ($data < $giorno) {
            throw new LetteraNonEmettibileException('La spedizione non può essere prima della lettera, del ' . Carbon::parse($giorno)->format('d/m/Y') . '.');
        }
    }

    // ── La PEC: la ricevuta e la consegna ────────────────────────────────

    /**
     * La ricevuta di consegna trascinata sulla riga di una lettera partita per PEC (decisione 577): si legge il `daticert.xml` e si controlla
     * ({@see RicevutaDellInvio::controlla()}: è una consegna, di questo indirizzo, di questa PEC, non dopo oggi né prima dell'invio); se va, l'esito è
     * «consegnata» con il giorno e l'ora del gestore e il file resta sulla riga come prova, con l'evento. La stessa consegna già registrata si allega soltanto;
     * una diversa è una correzione con il perché, e quella di prima resta fra gli eventi.
     *
     * Quando il file non si legge (un PDF, un `.eml` senza il `daticert.xml`), senza `$giorno` la risposta lo dice con `ricevuta_a_mano` (la pagina mostra il
     * giorno e l'ora dell'invio da confermare o correggere); con `$giorno` (e `$ora`) la consegna è scritta a mano, con il file come prova (decisione 588, L3).
     *
     * @throws ValidationException
     */
    public function registraLaRicevuta(RegistroInvio $riga, UploadedFile $file, ?User $utente, ?string $perche = null, ?string $giorno = null, ?string $ora = null): RegistroInvio
    {
        if ($riga->canale !== RegistroInvio::CANALE_PEC || ! in_array($riga->tipo, RegistroInvio::TIPI_LETTERE, true)
            || ! in_array($riga->stato, [RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_IN_CODA], true)) {
            throw ValidationException::withMessages(['ricevuta' => self::SOLO_PEC_PARTITE]);
        }
        if (RicevutaDellInvio::leggi($file) === null) {
            if ($giorno === null || trim($giorno) === '') {
                throw ValidationException::withMessages(['ricevuta' => self::RICEVUTA_NON_LETTA, 'ricevuta_a_mano' => 'proposta']);
            }

            return $this->consegnaAMano($riga, $file, trim($giorno), $ora, $utente, $perche);
        }
        $letta = RicevutaDellInvio::controlla($riga, $file, self::diQuesta($riga->tipo));
        $giorno = $letta->quando->format('Y-m-d');
        $ora = $letta->quando->format('H:i');
        if ($letta->eMancataConsegna()) {
            return $this->scriviLaMancataConsegna($riga, $file, $letta, $utente, $perche);
        }
        $aMano = $riga->esito === RegistroInvio::ESITO_CONSEGNATA && ! empty($riga->dati['consegna']['a_mano']);
        $stessa = $riga->esito === RegistroInvio::ESITO_CONSEGNATA && $riga->ricevuta_il?->format('Y-m-d') === $giorno
            && in_array((string) ($riga->dati['consegna']['ora'] ?? $riga->esito_il?->format('H:i')), [$ora, '00:00', ''], true);
        if ($stessa && ! $aMano && trim((string) $perche) === '') {
            // La stessa consegna, già registrata dalla ricevuta: questa si allega come prova.
            $this->allegaProva($riga, $file, $utente);

            return $riga->refresh();
        }
        if ($aMano && $riga->ricevuta_il?->format('Y-m-d') === $giorno && trim((string) $perche) === '') {
            // La consegna scritta a mano, con lo stesso giorno: la ricevuta del gestore la conferma, qualunque ora fosse scritta, e da adesso la consegna (con
            // l'ora del gestore) viene da lei.
            $perche = self::CONFERMA_DELLA_RICEVUTA;
        }

        return $this->scriviLaConsegna($riga, $file, $giorno, $ora, $utente, $perche, $letta->perLaRiga() + ['oggetto' => $letta->oggetto], false);
    }

    /** La consegna scritta a mano, quando la ricevuta non si legge: il giorno (non dopo oggi né prima dell'invio), l'ora se c'è, il file come prova. */
    private function consegnaAMano(RegistroInvio $riga, UploadedFile $file, string $giorno, ?string $ora, ?User $utente, ?string $perche): RegistroInvio
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno) !== 1 || ! checkdate((int) substr($giorno, 5, 2), (int) substr($giorno, 8, 2), (int) substr($giorno, 0, 4))) {
            throw ValidationException::withMessages(['giorno' => 'Scrivi il giorno della consegna.']);
        }
        if ($giorno > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno della consegna non può essere dopo oggi.']);
        }
        $dellInvio = RicevutaDellInvio::giornoDellInvio($riga);
        if ($giorno < $dellInvio) {
            throw ValidationException::withMessages(['giorno' => 'La consegna non può essere prima della PEC, partita il ' . Carbon::parse($dellInvio)->format('d/m/Y') . '.']);
        }
        $ora = $ora !== null && trim($ora) !== '' ? trim($ora) : null;
        if ($ora !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ora) !== 1) {
            throw ValidationException::withMessages(['ora' => 'Scrivi l\'ora come 10:14, o lasciala vuota.']);
        }
        // Lo stesso giorno dell'invio, l'ora non è prima di quella della PEC.
        $oraDellInvio = RicevutaDellInvio::oraDellInvio($riga);
        if ($ora !== null && $giorno === $dellInvio && $ora < $oraDellInvio) {
            throw ValidationException::withMessages(['ora' => 'La consegna non può essere prima della PEC, partita il ' . Carbon::parse($dellInvio)->format('d/m/Y')
                . ' alle ' . $oraDellInvio . '.']);
        }

        return $this->scriviLaConsegna($riga, $file, $giorno, $ora, $utente, $perche, null, true);
    }

    /**
     * L'esito «consegnata» sulla riga, con il file come prova e l'evento: dalla ricevuta del gestore o scritto a mano. Con un esito già registrato serve il
     * perché, e l'evento tiene quello di prima.
     *
     * @param ?array<string, ?string> $ricevuta i dati della ricevuta letta ({@see \App\Services\Posta\RicevutaPec::perLaRiga()})
     */
    private function scriviLaConsegna(RegistroInvio $riga, UploadedFile $file, string $giorno, ?string $ora, ?User $utente, ?string $perche, ?array $ricevuta,
        bool $aMano): RegistroInvio
    {
        $correzione = $riga->esito !== null;
        if ($correzione && trim((string) $perche) === '') {
            throw ValidationException::withMessages(['perche' => self::CONSEGNA_GIA_REGISTRATA]);
        }
        $prima = $correzione ? ['giorno' => $riga->ricevuta_il?->format('Y-m-d'), 'ora' => $riga->dati['consegna']['ora'] ?? null,
            'a_mano' => ! empty($riga->dati['consegna']['a_mano'])] : null;
        $impronta = (string) hash_file('sha256', (string) $file->getRealPath());
        $percorso = null;
        try {
            return DB::transaction(function () use ($riga, $file, $giorno, $ora, $utente, $perche, $ricevuta, $aMano, $correzione, $prima, $impronta, &$percorso) {
                [$documento, $percorso] = $this->archivia($riga, $file, $utente, ($aMano ? 'Prova della consegna della PEC del ' : 'Ricevuta di consegna della PEC del ')
                    . $riga->emesso_il->copy()->setTimezone(self::fuso())->format('d/m/Y'));
                $riga->refresh();
                $dati = (array) $riga->dati;
                $dati['consegna'] = array_filter(['giorno' => $giorno, 'ora' => $ora, 'a_mano' => $aMano, 'gestore' => $ricevuta['gestore'] ?? null,
                    'documento_id' => $documento->id], fn ($v) => $v !== null);
                $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento($aMano ? 'consegna_a_mano' : 'ricevuta', $utente) + array_filter([
                    'nome_file' => $documento->name, 'documento_id' => $documento->id, 'impronta' => $impronta, 'ricevuta' => $ricevuta,
                    'parole' => ConsegnaDellaPec::perLEvento($giorno, $ora) . ($aMano ? ', scritta a mano, con la prova' : ''),
                    'perche' => $correzione ? trim((string) $perche) : null, 'prima' => $prima,
                ], fn ($v) => $v !== null)];
                // Una PEC rimasta in coda con la sua consegna è partita: il gestore l'ha presa.
                $riga->update(['stato' => RegistroInvio::STATO_INVIATA, 'esito' => RegistroInvio::ESITO_CONSEGNATA, 'ricevuta_il' => $giorno,
                    'esito_il' => Carbon::parse($giorno . ' ' . ($ora ?? '00:00'), (string) config('app.timezone')), 'dati' => $dati]);

                return $riga->refresh();
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }
    }

    /**
     * L'avviso di mancata consegna del gestore sulla riga: l'esito «non consegnata» con il giorno e l'ora dell'avviso, il file come prova, l'evento. Con un
     * esito già registrato serve il perché, e l'evento tiene quello di prima.
     */
    private function scriviLaMancataConsegna(RegistroInvio $riga, UploadedFile $file, \App\Services\Posta\RicevutaPec $letta, ?User $utente, ?string $perche): RegistroInvio
    {
        $correzione = $riga->esito !== null;
        if ($correzione && trim((string) $perche) === '') {
            throw ValidationException::withMessages(['perche' => self::MANCATA_CONSEGNA_GIA_REGISTRATA]);
        }
        $giorno = $letta->quando->format('Y-m-d');
        $ora = $letta->quando->format('H:i');
        $impronta = (string) hash_file('sha256', (string) $file->getRealPath());
        $percorso = null;
        try {
            return DB::transaction(function () use ($riga, $file, $letta, $utente, $perche, $correzione, $giorno, $ora, $impronta, &$percorso) {
                [$documento, $percorso] = $this->archivia($riga, $file, $utente, 'Avviso di mancata consegna della PEC del '
                    . $riga->emesso_il->copy()->setTimezone(self::fuso())->format('d/m/Y'));
                $riga->refresh();
                $dati = (array) $riga->dati;
                $prima = $correzione ? ['esito' => $riga->esito, 'giorno' => $riga->ricevuta_il?->format('Y-m-d')] : null;
                $dati['mancata_consegna'] = array_filter(['giorno' => $giorno, 'ora' => $ora, 'gestore' => $letta->gestore, 'documento_id' => $documento->id],
                    fn ($v) => $v !== null);
                $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento('mancata_consegna', $utente) + array_filter([
                    'nome_file' => $documento->name, 'documento_id' => $documento->id, 'impronta' => $impronta, 'ricevuta' => $letta->perLaRiga() + ['oggetto' => $letta->oggetto],
                    'parole' => ConsegnaDellaPec::perLaMancataConsegna($giorno, $ora),
                    'perche' => $correzione ? trim((string) $perche) : null, 'prima' => $prima,
                ], fn ($v) => $v !== null)];
                $riga->update(['stato' => RegistroInvio::STATO_INVIATA, 'esito' => RegistroInvio::ESITO_NON_CONSEGNATA, 'ricevuta_il' => $giorno,
                    'esito_il' => Carbon::parse($giorno . ' ' . $ora, (string) config('app.timezone')), 'dati' => $dati]);

                return $riga->refresh();
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }
    }

    /**
     * Lo stato di una riga per le liste: una PEC che il gestore non ha consegnato (l'avviso di mancata consegna) vale come una PEC non partita. La lettera non
     * conta fra le arrivate, e il passo propone di mandarla di nuovo.
     */
    public static function statoDellaRiga(object $r): string
    {
        return $r->canale === RegistroInvio::CANALE_PEC && $r->esito === RegistroInvio::ESITO_NON_CONSEGNATA ? RegistroInvio::STATO_NON_PARTITA : (string) $r->stato;
    }

    /**
     * Lo stato di una riga per PEC per la corrispondenza: il badge e la frase ({@see ConsegnaDellaPec}), se la consegna è provata, se la riga prende la
     * ricevuta, se si manda di nuovo (una PEC non partita, finché dopo non c'è un'altra lettera dello stesso tipo uscita o in coda), e il giorno e l'ora
     * dell'invio, che la pagina propone quando la ricevuta non si legge.
     *
     * `ripetibile`: una PEC non partita, non consegnata o ferma in partenza, finché dopo non c'è un'altra lettera dello stesso tipo uscita o in coda;
     * `stampabile`: una PEC non partita o non consegnata, senza la copia su carta nella stessa lettera («Stampala per la posta»).
     *
     * @param Collection<int, RegistroInvio> $righe le righe della persona
     * @param Collection<int, RegistroInvio> $sue le righe della lettera
     * @return array{badge: string, frase: string, provata: bool, mancata_consegna: bool, ricevibile: bool, ripetibile: bool, stampabile: bool, invio: array{giorno: string, ora: string}}
     */
    private static function statoDellaPec(RegistroInvio $r, Collection $righe, ?\App\Services\Posta\ConfigurazionePec $casella, Collection $sue): array
    {
        $invio = $r->emesso_il->copy()->setTimezone(self::fuso());
        $daRifare = ! self::superata($r, $righe);
        $nonArrivata = $r->stato === RegistroInvio::STATO_NON_PARTITA || ConsegnaDellaPec::nonConsegnata($r);

        return [
            'badge' => ConsegnaDellaPec::badge($r),
            'frase' => ConsegnaDellaPec::frase($r, $casella),
            'provata' => ConsegnaDellaPec::provata($r),
            'mancata_consegna' => ConsegnaDellaPec::nonConsegnata($r),
            'ricevibile' => in_array($r->stato, [RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_IN_CODA], true),
            'ripetibile' => EmissioneLettere::daRimandarePerPec($r) && $daRifare,
            'stampabile' => $nonArrivata && $daRifare && ! $sue->contains(fn (RegistroInvio $x) => $x->canale === RegistroInvio::CANALE_STAMPA),
            'invio' => ['giorno' => $invio->format('Y-m-d'), 'ora' => $invio->format('H:i')],
        ];
    }

    /**
     * Il termine «N giorni dal ricevimento» contato dalla consegna della PEC provata (la prima, con «PEC e carta»): il giorno, i giorni, da quale consegna.
     * Null senza la consegna provata o senza un termine in giorni.
     *
     * @param array<string, mixed> $dati i dati della lettera
     * @param Collection<int, RegistroInvio> $sue le righe della lettera
     * @return array{entro: string, giorni: int, consegna: string, a_mano: bool}|null
     */
    private static function termineDallaConsegna(array $dati, Collection $sue): ?array
    {
        $consegna = $sue->filter(fn (RegistroInvio $r) => ConsegnaDellaPec::provata($r))->sortBy(fn (RegistroInvio $r) => $r->ricevuta_il->format('Y-m-d'))->first();
        if ($consegna === null || TestiLettere::formaDelTermine($dati) !== 'giorni' || ! isset($dati['termine'])) {
            return null;
        }
        $giorno = $consegna->ricevuta_il->format('Y-m-d');

        return ['entro' => CarbonImmutable::parse($giorno, 'UTC')->addDays((int) $dati['termine'])->format('Y-m-d'), 'giorni' => (int) $dati['termine'],
            'consegna' => $giorno, 'a_mano' => ! empty($consegna->dati['consegna']['a_mano'])];
    }

    /**
     * I documenti della riga da cui viene l'esito (la ricevuta del gestore, l'avviso di mancata consegna, o la prova della consegna scritta a mano, anche quelli
     * di prima): non si tolgono. Con `$evento` (`consegna_a_mano`, `mancata_consegna`), i soli documenti di quegli eventi.
     *
     * @return list<int>
     */
    public static function documentiDellaConsegna(RegistroInvio $riga, ?string $evento = null): array
    {
        $eventi = $evento !== null ? [$evento] : ['ricevuta', 'consegna_a_mano', 'mancata_consegna'];
        $ids = array_map(fn ($e) => (int) ($e['documento_id'] ?? 0), array_filter((array) ($riga->dati['eventi'] ?? []),
            fn ($e) => in_array($e['evento'] ?? null, $eventi, true)));
        if ($evento !== null) {
            return array_values(array_filter(array_unique($ids)));
        }

        return array_values(array_filter(array_unique([...$ids, (int) ($riga->dati['consegna']['documento_id'] ?? 0),
            (int) ($riga->dati['mancata_consegna']['documento_id'] ?? 0)])));
    }

    /** Una lettera di prima andata per email alla PEC della persona: oggi la PEC non è un indirizzo dell'email (decisione 588, L2). */
    public static function perEmailAllaPec(RegistroInvio $riga): bool
    {
        return $riga->canale === RegistroInvio::CANALE_EMAIL && ($riga->dati['tipo_indirizzo'] ?? null) === 'pec';
    }

    /** «di questo sollecito», «di questa diffida»: di che cosa è la PEC, per la frase della ricevuta di un'altra PEC. */
    private static function diQuesta(string $tipo): string
    {
        return match ($tipo) {
            'sollecito' => 'di questo sollecito',
            'promemoria' => 'di questo promemoria',
            'messa_in_mora' => 'di questa messa in mora',
            default => 'di questa diffida',
        };
    }

    /** «Allega la prova»: un documento della riga, che non entra nell'archivio né nel portale, e l'evento. */
    public function allegaProva(RegistroInvio $riga, UploadedFile $file, ?User $utente): Documento
    {
        if (! in_array($riga->stato, RegistroInvio::STATI_LETTERA_USCITA, true)) {
            throw new LetteraNonEmettibileException(self::PROVA_SU_USCITE);
        }
        $percorso = null;
        try {
            return DB::transaction(function () use ($riga, $file, $utente, &$percorso) {
                [$documento, $percorso] = $this->archivia($riga, $file, $utente,
                    ($riga->tipo === RegistroInvio::TIPO_CONVOCAZIONE ? 'Prova della convocazione del ' : ($riga->tipo === RegistroInvio::TIPO_VERBALE
                        ? 'Prova della trasmissione del verbale del ' : 'Prova della spedizione della lettera del '))
                    . $riga->emesso_il->copy()->setTimezone(self::fuso())->format('d/m/Y'));
                $riga->refresh();
                $dati = (array) $riga->dati;
                $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento('prova_allegata', $utente) + ['documento_id' => $documento->id, 'nome_file' => $documento->name]];
                $riga->update(['dati' => $dati]);

                return $documento;
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }
    }

    /**
     * Il file sul disco privato, con l'estensione dichiarata (una delle ammesse, non quella che il contenuto fa indovinare) e un nome casuale, e il suo
     * documento della riga. Si chiama dentro una transazione; il percorso torna a chi chiama, che lo toglie se l'operazione non riesce.
     *
     * @return array{0: Documento, 1: string}
     */
    private function archivia(RegistroInvio $riga, UploadedFile $file, ?User $utente, string $descrizione): array
    {
        $percorso = $this->archivio->salvaConLEstensioneDelNome($file, ArchivioDocumenti::CARTELLA . '/' . $riga->condominio_id
            . ($riga->tipo === RegistroInvio::TIPO_CONVOCAZIONE ? '/convocazioni' : ($riga->tipo === RegistroInvio::TIPO_VERBALE ? '/verbali' : '/lettere')));
        $documento = $riga->documenti()->create([
            'name' => $file->getClientOriginalName(),
            'description' => $descrizione,
            'path' => $percorso,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'created_by' => $utente?->id,
            'is_published' => false,
            'is_approved' => true,
        ]);

        return [$documento, $percorso];
    }

    /** «Togli la prova»: solo finché l'esercizio della lettera non è chiuso. Il file va via; l'evento resta, con il nome del file. */
    public function togliProva(RegistroInvio $riga, Documento $documento, ?User $utente): void
    {
        abort_unless($documento->documentable_type === RegistroInvio::class && (int) $documento->documentable_id === (int) $riga->id
            && (int) ($riga->dati['documento_id'] ?? 0) !== (int) $documento->id, 404);
        // La ricevuta di consegna della PEC, o la prova della consegna scritta a mano: senza, l'esito direbbe una consegna senza prova.
        if (in_array((int) $documento->id, self::documentiDellaConsegna($riga), true)) {
            throw new LetteraNonEmettibileException(self::PROVA_DELLA_CONSEGNA);
        }
        if (! $this->provaRimovibile($riga)) {
            throw new LetteraNonEmettibileException(self::PROVA_FERMA);
        }
        DB::transaction(function () use ($riga, $documento, $utente) {
            $dati = (array) $riga->dati;
            $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento('prova_tolta', $utente) + ['documento_id' => $documento->id, 'nome_file' => $documento->name]];
            $riga->update(['dati' => $dati]);
            $documento->delete();
        });
        $this->archivio->elimina($documento->path);
    }

    /**
     * Una prova si toglie finché l'esercizio che contiene la data della lettera è aperto; se nessun esercizio la contiene (gennaio, con
     * l'esercizio nuovo non ancora aperto), si toglie; con due esercizi che la contengono, basta che uno sia chiuso per non toglierla. La prova di una
     * convocazione non si toglie mai.
     */
    public function provaRimovibile(RegistroInvio $riga): bool
    {
        // La prova di una convocazione (e della trasmissione del verbale) non si toglie mai (§4.4 del progetto della tappa 2): un errore si corregge con un
        // fatto nuovo, che tiene quello di prima.
        if (in_array($riga->tipo, RegistroInvio::TIPI_DELL_ASSEMBLEA, true)) {
            return false;
        }
        $giorno = $riga->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d');

        return ! DB::table('esercizi')->where('condominio_id', $riga->condominio_id)
            ->where('data_inizio', '<=', $giorno)->where('data_fine', '>=', $giorno)
            ->where('stato', 'chiuso')->exists();
    }

    /** L'impronta del file conservato, letta dal disco: null se il file non c'è. */
    public function impronta(Documento $documento): ?string
    {
        $contenuto = $this->archivio->leggi($documento->path);

        return $contenuto === null ? null : hash('sha256', $contenuto);
    }

    /**
     * Le righe chieste, tutte di questo condominio e tutte di lettere (o dei tipi dati): altrimenti 404 per tutta la richiesta.
     *
     * @param list<int> $ids
     * @param list<string> $tipi
     * @return Collection<int, RegistroInvio>
     */
    public function righeDelCondominio(Condominio $condominio, array $ids, array $tipi = RegistroInvio::TIPI_LETTERE): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        abort_if($ids === [], 404);
        $righe = RegistroInvio::whereIn('id', $ids)->where('condominio_id', $condominio->id)->whereIn('tipo', $tipi)->get();
        abort_unless($righe->count() === count($ids), 404);

        return $righe;
    }

    /** Lo stato di una lettera con più righe (una per indirizzo): il più avanti. */
    public static function statoDiLettera(array $stati): string
    {
        usort($stati, fn ($a, $b) => (self::RANGO[$b] ?? -1) <=> (self::RANGO[$a] ?? -1));

        return (string) ($stati[0] ?? RegistroInvio::STATO_NON_PARTITA);
    }

    /**
     * Un evento del registro: l'istante e, a parte, il giorno nel calendario dell'amministratore, che la pagina mostra (alle 00:30 di Roma
     * l'istante in UTC è ancora il giorno prima).
     *
     * @return array{evento: string, il: string, giorno: string, da: ?int, da_nome: ?string}
     */
    private static function evento(string $evento, ?User $utente): array
    {
        return ['evento' => $evento, 'il' => now()->toIso8601String(), 'giorno' => DateHelper::oggiUtente(), 'da' => $utente?->id, 'da_nome' => $utente?->name];
    }

    /**
     * Una riga per email è superata quando dopo di lei la stessa persona ha un'altra lettera dello stesso tipo uscita o in coda: allora non
     * si rifà né si rimanda (due clic, o due schede, non fanno due lettere). Gli altri indirizzi della stessa lettera non contano.
     *
     * @param Collection<int, RegistroInvio> $righe le righe della persona (anche di altri tipi)
     */
    public static function superata(RegistroInvio $riga, Collection $righe): bool
    {
        $lettera = $riga->dati['lettera'] ?? null;

        return $righe->contains(fn (RegistroInvio $altra) => $altra->id > $riga->id && (int) $altra->anagrafica_id === (int) $riga->anagrafica_id
            && $altra->tipo === $riga->tipo && ($lettera === null || ($altra->dati['lettera'] ?? null) !== $lettera)
            && in_array($altra->stato, [...RegistroInvio::STATI_LETTERA_USCITA, RegistroInvio::STATO_IN_CODA], true));
    }

    /**
     * Una lettera dalle sue righe, per l'elenco: il tipo, il giorno, il canale, lo stato, gli indirizzi, la spedizione; le righe da spedire
     * (stampate o scaricate, spedizione non registrata), per registrarla in blocco dall'elenco; il giorno che conta per «nessuna lettera negli
     * ultimi N giorni», che per una lettera spedita è quello della spedizione. Per una lettera per PEC il canale `pec` (o `pec_e_carta`, con la copia su
     * carta) e la consegna provata (`consegna`: il giorno della ricevuta, o scritto a mano con la prova), da cui si conta il termine.
     */
    private static function lettera(Collection $righe): array
    {
        $prima = $righe->first();
        $spedita = $righe->first(fn ($r) => $r->spedizione_canale !== null);
        $data = $prima->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d');
        $dataSpedizione = $spedita === null ? null : self::testoJson($spedita->spedizione_data);
        $conLaPec = $righe->first(fn ($r) => $r->canale === RegistroInvio::CANALE_PEC);
        $consegnate = $righe->filter(fn ($r) => $r->canale === RegistroInvio::CANALE_PEC && $r->esito === RegistroInvio::ESITO_CONSEGNATA && $r->ricevuta_il !== null)
            ->sortBy(fn ($r) => $r->ricevuta_il->format('Y-m-d'));
        $consegna = $consegnate->first();
        $pecECarta = $righe->contains(fn ($r) => in_array(self::testoJson($r->pec_e_carta), ['1', 'true'], true));
        // Lo stato della PEC della lettera, per le parole dell'elenco: «PEC e carta» stampata con la PEC da mandare non dice «PEC partita».
        $statoDellaPec = match (true) {
            $conLaPec === null => $pecECarta ? 'da_mandare' : null,
            $conLaPec->esito === RegistroInvio::ESITO_NON_CONSEGNATA => RegistroInvio::ESITO_NON_CONSEGNATA,
            $conLaPec->stato === RegistroInvio::STATO_INVIATA => 'partita',
            $conLaPec->stato === RegistroInvio::STATO_NON_PARTITA => 'non_partita',
            default => 'in_coda',
        };

        return [
            'invii'       => $righe->filter(fn ($r) => $r->canale === RegistroInvio::CANALE_STAMPA && $r->stato === RegistroInvio::STATO_STAMPATA)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'data_dei_filtri' => $dataSpedizione !== null && $dataSpedizione > $data ? $dataSpedizione : $data,
            'id'          => (int) $righe->max('id'),
            'quando'      => $prima->emesso_il->toIso8601String(),
            'tipo'        => $prima->tipo,
            'data'        => $data,
            'canale'      => $pecECarta ? 'pec_e_carta' : ($conLaPec !== null ? RegistroInvio::CANALE_PEC
                : ($prima->canale === RegistroInvio::CANALE_STAMPA && $prima->formato === 'zip' ? 'zip' : $prima->canale)),
            'stato'       => self::statoDiLettera($righe->map(fn ($r) => self::statoDellaRiga($r))->all()),
            'destinatari' => $righe->pluck('destinatario')->filter()->values()->all(),
            'spedizione'  => $spedita === null ? null : array_filter(['canale' => self::testoJson($spedita->spedizione_canale), 'data' => self::testoJson($spedita->spedizione_data),
                'numero' => self::testoJson($spedita->spedizione_numero)], fn ($v) => $v !== null),
            // La consegna della PEC provata (la prima, con «PEC e carta» o più righe): il giorno, e se è scritta a mano con la prova.
            'consegna'    => $consegna === null ? null : ['giorno' => $consegna->ricevuta_il->format('Y-m-d'),
                'a_mano' => in_array(self::testoJson($consegna->consegna_a_mano), ['1', 'true'], true)],
            // La PEC della lettera: `partita`, `non_partita`, `in_coda`, l'esito dell'avviso di mancata consegna (con il giorno dell'avviso), `da_mandare`
            // («PEC e carta» solo stampata), o null senza la PEC.
            'pec'         => $statoDellaPec,
            'mancata_consegna_il' => $statoDellaPec === RegistroInvio::ESITO_NON_CONSEGNATA ? $conLaPec->ricevuta_il?->format('Y-m-d') : null,
            // «PEC e carta» con la copia su carta stampata e non spedita: il passo ricorda di registrarne la spedizione.
            'copia_da_spedire' => $pecECarta && $righe->contains(fn ($r) => $r->canale === RegistroInvio::CANALE_STAMPA && $r->stato === RegistroInvio::STATO_STAMPATA),
        ];
    }

    /** Un valore letto dal JSON con `->`: su MySQL arriva con le virgolette, su SQLite senza. */
    private static function testoJson(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = (string) $v;

        return str_starts_with($v, '"') ? (string) json_decode($v) : $v;
    }

    private static function fuso(): string
    {
        return (string) config('app.user_timezone', 'Europe/Rome');
    }
}
