<?php

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
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /** L'ordine degli stati di una lettera con più righe: vale il più avanti. */
    private const RANGO = [RegistroInvio::STATO_NON_PARTITA => 0, RegistroInvio::STATO_IN_CODA => 1, RegistroInvio::STATO_STAMPATA => 2,
        RegistroInvio::STATO_INVIATA => 3, RegistroInvio::STATO_SPEDITA => 4];

    public function __construct(private readonly LetturaDebiti $lettura, private readonly ArchivioDocumenti $archivio, private readonly AccordiRientro $accordi) {}

    /**
     * L'ultima lettera di ogni persona alla data (quelle partite, stampate o spedite) e, se dopo ce n'è una in coda o non partita, quella; e a
     * parte l'ultima che conta per i filtri dell'elenco (`conta`): partita o spedita, non solo stampata o scaricata.
     *
     * @param list<int> $persone
     * @return array<int, array{ultima: ?array<string,mixed>, in_sospeso: ?array<string,mixed>, conta: ?array<string,mixed>}>
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
            ->get(['id', 'anagrafica_id', 'tipo', 'canale', 'stato', 'destinatario', 'emesso_il', 'dati->lettera as lettera', 'dati->formato as formato',
                'dati->spedizione->canale as spedizione_canale', 'dati->spedizione->data as spedizione_data', 'dati->spedizione->numero as spedizione_numero']);

        $esito = [];
        foreach ($righe->groupBy('anagrafica_id') as $persona => $sue) {
            $lettere = $sue->groupBy(fn ($r) => (string) ($r->lettera ?? 'riga-' . $r->id))->map(fn (Collection $g) => self::lettera($g))
                ->sortBy(fn ($l) => [$l['quando'], $l['id']])->values();
            $uscite = $lettere->filter(fn ($l) => in_array($l['stato'], RegistroInvio::STATI_LETTERA_USCITA, true));
            $ultima = $uscite->last();
            $dopo = $lettere->filter(fn ($l) => ! in_array($l['stato'], RegistroInvio::STATI_LETTERA_USCITA, true) && ($ultima === null || $l['id'] > $ultima['id']))->last();
            // Quella che conta è la più recente per il giorno dei filtri (per una spedita il giorno della spedizione), non l'ultima emessa: una
            // diffida stampata prima e spedita dopo un promemoria è la lettera più recente.
            $conta = $lettere->filter(fn ($l) => in_array($l['stato'], RegistroInvio::STATI_LETTERA_ARRIVATA, true))->sortBy('data_dei_filtri')->last();
            $esito[(int) $persona] = ['ultima' => $ultima, 'in_sospeso' => $dopo, 'conta' => $conta];
        }

        return $esito;
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

        $lettura = $this->lettura->perPersona((int) $condominio->id, (int) $persona->id)['rate'];
        $oggi = collect($lettura)->mapWithKeys(fn ($r) => [$r['rata_id'] . '|' . ($r['immobile_id'] ?? '') => (int) $r['residuo']]);
        // La parte di ogni rata che oggi un accordo di rientro in corso lascia fuori dalle lettere.
        $inAccordoOggi = $this->accordi->fuoriDalleLettere((int) $condominio->id, (int) $persona->id, $lettura, DateHelper::oggiUtente());
        $documenti = Documento::where('documentable_type', RegistroInvio::class)->whereIn('documentable_id', $righe->pluck('id'))->get()->keyBy('id');
        $deiDocumenti = $documenti->groupBy('documentable_id');

        $lettere = [];
        foreach ($righe->groupBy(fn (RegistroInvio $r) => (string) ($r->dati['lettera'] ?? 'riga-' . $r->id)) as $chiave => $sue) {
            $prima = $sue->first();
            $dati = (array) $prima->dati;
            $conservato = isset($dati['documento_id']) ? $documenti->get((int) $dati['documento_id']) : null;
            $prove = $sue->flatMap(fn (RegistroInvio $r) => ($deiDocumenti[$r->id] ?? collect())->filter(fn (Documento $d) => $conservato === null || $d->id !== $conservato->id)
                ->map(fn (Documento $d) => ['id' => $d->id, 'invio_id' => $r->id, 'nome' => $d->name, 'aggiunta_il' => $d->created_at?->copy()->setTimezone(self::fuso())->format('Y-m-d'),
                    'rimovibile' => $this->provaRimovibile($r)]))->values()->all();
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
                'stato'        => self::statoDiLettera($sue->pluck('stato')->all()),
                'righe'        => $sue->map(fn (RegistroInvio $r) => [
                    'id' => $r->id, 'canale' => $r->canale, 'formato' => $r->dati['formato'] ?? null, 'destinatario' => $r->destinatario, 'stato' => $r->stato,
                    'quando' => $r->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d H:i'),
                    'errore' => \App\Services\Gestionale\Ricevute\ErroreDiPosta::frase($r->errore),
                    'spedizione' => $r->dati['spedizione'] ?? null,
                    'spedibile' => $r->canale === RegistroInvio::CANALE_STAMPA && $r->stato === RegistroInvio::STATO_STAMPATA,
                    // «Invia di nuovo»: una lettera per email partita o non partita, finché dopo non ce n'è un'altra dello stesso tipo uscita o in coda.
                    'rimandabile' => $r->canale === RegistroInvio::CANALE_EMAIL && $r->stato !== RegistroInvio::STATO_IN_CODA && ! in_array($r->tipo, EmissioneLettere::TIPI_SOLO_CARTA, true)
                        && ! self::superata($r, $righe),
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
     * data (non dopo oggi), il numero di ognuna se c'è, una nota. Tutte o nessuna.
     *
     * @param list<int> $invii
     * @param array<int|string, mixed> $numeri
     */
    public function registraSpedizione(Condominio $condominio, array $invii, string $canale, string $data, array $numeri, ?string $nota, ?User $utente): int
    {
        $righe = $this->righeDelCondominio($condominio, $invii);
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

    /** «Allega la prova»: un documento della riga, che non entra nell'archivio né nel portale, e l'evento. */
    public function allegaProva(RegistroInvio $riga, UploadedFile $file, ?User $utente): Documento
    {
        if (! in_array($riga->stato, RegistroInvio::STATI_LETTERA_USCITA, true)) {
            throw new LetteraNonEmettibileException(self::PROVA_SU_USCITE);
        }
        $percorso = null;
        try {
            return DB::transaction(function () use ($riga, $file, $utente, &$percorso) {
                // Sul disco l'estensione dichiarata (una delle ammesse), non quella che il contenuto fa indovinare.
                $percorso = $this->archivio->salvaConLEstensioneDelNome($file, ArchivioDocumenti::CARTELLA . '/' . $riga->condominio_id . '/lettere');
                $documento = $riga->documenti()->create([
                    'name' => $file->getClientOriginalName(),
                    'description' => 'Prova della spedizione della lettera del ' . $riga->emesso_il->copy()->setTimezone(self::fuso())->format('d/m/Y'),
                    'path' => $percorso,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'created_by' => $utente?->id ?? 1,
                    'is_published' => false,
                    'is_approved' => true,
                ]);
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

    /** «Togli la prova»: solo finché l'esercizio della lettera non è chiuso. Il file va via; l'evento resta, con il nome del file. */
    public function togliProva(RegistroInvio $riga, Documento $documento, ?User $utente): void
    {
        abort_unless($documento->documentable_type === RegistroInvio::class && (int) $documento->documentable_id === (int) $riga->id
            && (int) ($riga->dati['documento_id'] ?? 0) !== (int) $documento->id, 404);
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
     * l'esercizio nuovo non ancora aperto), si toglie; con due esercizi che la contengono, basta che uno sia chiuso per non toglierla.
     */
    public function provaRimovibile(RegistroInvio $riga): bool
    {
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
     * Le righe chieste, tutte di questo condominio e tutte di lettere: altrimenti 404 per tutta la richiesta.
     *
     * @param list<int> $ids
     * @return Collection<int, RegistroInvio>
     */
    public function righeDelCondominio(Condominio $condominio, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        abort_if($ids === [], 404);
        $righe = RegistroInvio::whereIn('id', $ids)->where('condominio_id', $condominio->id)->whereIn('tipo', RegistroInvio::TIPI_LETTERE)->get();
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
     * ultimi N giorni», che per una lettera spedita è quello della spedizione.
     */
    private static function lettera(Collection $righe): array
    {
        $prima = $righe->first();
        $spedita = $righe->first(fn ($r) => $r->spedizione_canale !== null);
        $data = $prima->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d');
        $dataSpedizione = $spedita === null ? null : self::testoJson($spedita->spedizione_data);

        return [
            'invii'       => $righe->filter(fn ($r) => $r->canale === RegistroInvio::CANALE_STAMPA && $r->stato === RegistroInvio::STATO_STAMPATA)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'data_dei_filtri' => $dataSpedizione !== null && $dataSpedizione > $data ? $dataSpedizione : $data,
            'id'          => (int) $righe->max('id'),
            'quando'      => $prima->emesso_il->toIso8601String(),
            'tipo'        => $prima->tipo,
            'data'        => $prima->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d'),
            'canale'      => $prima->canale === RegistroInvio::CANALE_STAMPA && $prima->formato === 'zip' ? 'zip' : $prima->canale,
            'stato'       => self::statoDiLettera($righe->pluck('stato')->all()),
            'destinatari' => $righe->pluck('destinatario')->filter()->values()->all(),
            'spedizione'  => $spedita === null ? null : array_filter(['canale' => self::testoJson($spedita->spedizione_canale), 'data' => self::testoJson($spedita->spedizione_data),
                'numero' => self::testoJson($spedita->spedizione_numero)], fn ($v) => $v !== null),
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
