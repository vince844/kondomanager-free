<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\EsitoInvio;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\Posta\RicevutaDellInvio;
use App\Services\Posta\RicevutaPec;
use App\Services\RegistroModifiche\RegistroModifiche;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La spedizione e la prova di consegna della convocazione (§4.3 e §4.4 del progetto della tappa 2; legge §1).
 *
 * - **«Registra la spedizione»**: la raccomandata con il numero, il fax, come le lettere ({@see RegistroLettere::registraSpedizione()}, esteso al tipo
 *   `convocazione`). Un avviso «a mano» registrato come spedito è anche consegnato quel giorno. Si registra solo la lettera di oggi: una lettera stampata
 *   prima che cambiassero date o punti, o già ristampata, si rifiuta.
 * - **La prima spedizione registrata** fa l'assemblea «convocata» ({@see EmissioneDelleConvocazioni::dopoLaSpedizione()}): la raccomandata registrata
 *   spedita, la consegna a mano registrata.
 * - **«Registra la consegna»**: l'esito nelle colonne della riga (`esito`, `esito_il`, `ricevuta_il`) e ogni fatto in `dati.eventi`, che non si cancella.
 *   `ricevuta_il` è il giorno che conta per i cinque giorni: il primo fra la consegna e l'avviso di giacenza (Cass. 23396/2017), e un «ritirata» dopo la
 *   giacenza non lo sposta; per «rifiutata» e «non consegnata» resta vuoto (la rifiutata non conta da sola: decide il presidente, §4.7).
 * - **Ogni esito registrato e ogni sua correzione è anche una riga del registro delle modifiche** sull'assemblea, con la persona fra i soggetti («Raccomandata
 *   a Neri Carla: in giacenza, avviso del 07/12»; «Ricevuta di Neri Carla corretta: 10/12 → 09/12», con il perché): il giorno che decide la validità sta
 *   così nella catena firmata. Un esito già registrato si cambia solo con il perché, e il fatto di prima resta fra gli eventi.
 * - **La prova** è un documento della riga (la ricevuta della PEC, l'avviso di giacenza, la cartolina, il foglio firmato) e **non si toglie**; il suo nome e
 *   la sua impronta stanno nella riga del registro dell'esito, e «Allega la prova» è una riga sua.
 * - **La ricevuta PEC** trascinata sulla riga si legge ({@see RicevutaPec}): giorno e ora dal gestore (decisione 577).
 * - **Il foglio delle consegne a mano**: la scansione caricata una volta vale per tutte le sue righe, con il giorno scritto accanto a ognuna.
 * - **Anche il verbale** (il tipo `verbale` del registro degli invii): la spedizione, la consegna, la prova e la ricevuta PEC della trasmissione del verbale
 *   passano da qui, con le stesse regole; il «dopo» di una riga del verbale rifà i compiti del verbale, non lo stato della convocazione, e le lettere del
 *   verbale non si confrontano con l'avviso di oggi.
 */
final class ConsegneDellaConvocazione
{
    public const GIA_REGISTRATO = 'Questa persona ha già un esito registrato: per cambiarlo scrivi perché lo correggi.';

    public const NON_SPEDITO = 'Si registra la consegna di un avviso spedito, partito o stampato per la consegna a mano: questo non lo è.';

    /** «Ritirata»: la raccomandata in giacenza ritirata dopo l'avviso. Diventa «Consegnata», e il giorno che conta resta quello dell'avviso. */
    public const RITIRATA = 'ritirata';

    /** Gli esiti di ogni mezzo: la PEC e il fax si consegnano o no; a mano si consegna o si rifiuta; la raccomandata li ha tutti. */
    public const ESITI_PER_MEZZO = [
        'pec' => ['esiti' => ['consegnata', 'non_consegnata'], 'frase' => 'Per una PEC l\'esito è «Consegnata» o «Non consegnata».'],
        'a_mano' => ['esiti' => ['consegnata', 'rifiutata'], 'frase' => 'Per una consegna a mano l\'esito è «Consegnata» o «Rifiutata».'],
        'fax' => ['esiti' => ['consegnata', 'non_consegnata'], 'frase' => 'Per un fax l\'esito è «Consegnata» o «Non consegnata».'],
    ];

    public function __construct(
        private readonly RegistroLettere $lettere,
        private readonly ArchivioDocumenti $archivio,
        private readonly ElencoDeiConvocati $elenco,
        private readonly TerminiDellaConvocazione $termini,
        private readonly PdfService $pdf,
    ) {}

    /**
     * Il foglio delle consegne a mano da stampare: chi riceve l'avviso a mano e non l'ha ancora ricevuto (o le persone date), con il giorno e la firma.
     *
     * @param ?list<int> $anagrafiche
     */
    public function foglioDaStampare(Assemblea $assemblea, ?array $anagrafiche = null): string
    {
        $voci = array_values(array_filter($this->elenco->per($assemblea), fn ($v) => (($v['invio']?->dati['mezzo'] ?? null) === 'a_mano'
            || ($v['invio'] === null && $v['mezzo']['mezzo'] === 'a_mano' && $v['mezzo']['valido'])) && $v['invio']?->esito === null
            && ($anagrafiche === null || in_array((int) $v['anagrafica_id'], $anagrafiche, true))));
        if ($voci === []) {
            throw ValidationException::withMessages(['foglio' => 'Nessuna consegna a mano da fare.']);
        }
        $ultimo = $this->termini->ultimoGiorno($assemblea);

        return $this->pdf->generate('pdf.gestionale.assemblea_foglio_consegne', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $assemblea->condominio,
            'assemblea' => 'Assemblea ' . mb_strtolower($assemblea->nome()),
            'frase' => 'Ricevo l\'avviso di convocazione dell\'assemblea, con l\'ordine del giorno e la delega.'
                . ($ultimo !== null ? ' Le consegne si fanno ' . self::entro($ultimo, 'd/m/Y') . ', cinque giorni prima della prima convocazione.' : ''),
            'righe' => array_map(fn ($v) => ['persona' => (string) $v['nome'] . ($v['ruolo'] !== null ? ' (' . $v['ruolo'] . ')' : ''), 'unita' => (string) $v['unita_parole']], $voci),
            'piede' => 'Firmato, il foglio si scansiona e si carica nel passo «Convocazione» dell\'assemblea: vale per tutte le righe, ognuna con il suo giorno.',
        ])->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * «Registra la spedizione» degli avvisi stampati: `raccomandata` (con il numero di ognuna), `fax`, o `a_mano` (allora anche consegnati quel giorno).
     *
     * @param list<int> $invii
     * @param array<int|string, mixed> $numeri
     */
    public function registraSpedizione(Assemblea $assemblea, array $invii, string $canale, string $data, array $numeri, ?string $nota, User $utente,
        string $tipo = RegistroInvio::TIPO_CONVOCAZIONE): int
    {
        $righe = $this->righeDellAssemblea($assemblea, $invii, $tipo);
        // Il canale è quello dell'avviso stampato: una raccomandata non diventa un fax (con la destinazione d'uso il fax non vale, art. 1117-ter).
        foreach ($righe as $r) {
            $mezzo = (string) ($r->dati['mezzo'] ?? '');
            if ($mezzo !== '' && $mezzo !== $canale) {
                throw ValidationException::withMessages(['invii' => 'L\'avviso di ' . ($r->dati['persona']['nome'] ?? 'questa persona') . ' è per '
                    . mb_strtolower(MezzoDiConvocazione::parola($mezzo)) . ': registralo come ' . mb_strtolower(MezzoDiConvocazione::parola($mezzo)) . '.']);
            }
        }
        if ($canale === 'a_mano') {
            return $this->consegnaAMano($assemblea, $righe->map(fn (RegistroInvio $r) => ['invio_id' => (int) $r->id, 'giorno' => $data])->all(), null, $utente, $tipo);
        }
        $this->soloLettereDiOggi($assemblea, $righe, 'invii');
        $quante = $this->lettere->registraSpedizione($assemblea->condominio, $righe->pluck('id')->all(), $canale, $data, $numeri, $nota, $utente, [$tipo]);
        $this->dopo($assemblea, $utente, true, $tipo);

        return $quante;
    }

    /**
     * «Registra la consegna» di un invio: l'esito, il giorno, l'ora (facoltativa), la prova (facoltativa). Con un esito già registrato serve `$perche`, ed è
     * una correzione; «ritirata» dopo la giacenza è invece un fatto nuovo, senza perché, e il giorno che conta resta l'avviso.
     *
     * Gli esiti dipendono dal mezzo ({@see ESITI_PER_MEZZO}); per la raccomandata e il fax il giorno non viene prima della spedizione registrata. La prova
     * caricata entra nel registro con il suo nome e la sua impronta.
     *
     * @param ?array<string, ?string> $ricevuta i dati della ricevuta PEC letta dal file ({@see RicevutaPec::perLaRiga()})
     */
    public function registra(Assemblea $assemblea, RegistroInvio $invio, string $esito, string $giorno, ?string $ora, ?UploadedFile $prova, User $utente,
        ?string $perche = null, ?array $ricevuta = null): RegistroInvio
    {
        $ritiro = $esito === self::RITIRATA;
        if ($ritiro) {
            if ($invio->esito !== RegistroInvio::ESITO_IN_GIACENZA || $invio->ricevuta_il === null) {
                throw ValidationException::withMessages(['esito' => 'Il ritiro si registra su una raccomandata in giacenza.']);
            }
            if ($giorno < $invio->ricevuta_il->format('Y-m-d')) {
                throw ValidationException::withMessages(['giorno' => 'Il ritiro non può essere prima dell\'avviso di giacenza, del ' . $invio->ricevuta_il->format('d/m/Y') . '.']);
            }
        }
        $esitoNuovo = $ritiro ? EsitoInvio::CONSEGNATA : (EsitoInvio::tryFrom($esito) ?? throw ValidationException::withMessages(['esito' => 'Scegli l\'esito.']));
        if (! in_array($invio->stato, [RegistroInvio::STATO_SPEDITA, RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_STAMPATA, RegistroInvio::STATO_IN_CODA], true)) {
            throw ValidationException::withMessages(['esito' => self::NON_SPEDITO]);
        }
        $mezzo = (string) ($invio->dati['mezzo'] ?? ($invio->canale === RegistroInvio::CANALE_PEC ? 'pec' : ''));
        if ($invio->stato === RegistroInvio::STATO_STAMPATA && $mezzo !== 'a_mano') {
            throw ValidationException::withMessages(['esito' => 'Prima registra la spedizione: la raccomandata non risulta ancora spedita.']);
        }
        $this->soloLettereDiOggi($assemblea, [$invio], 'esito');
        if (isset(self::ESITI_PER_MEZZO[$mezzo]) && ! in_array($esitoNuovo->value, self::ESITI_PER_MEZZO[$mezzo]['esiti'], true)) {
            throw ValidationException::withMessages(['esito' => self::ESITI_PER_MEZZO[$mezzo]['frase']]);
        }
        $giornoDellInvio = $invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d');
        if ($giorno < $giornoDellInvio) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno non può essere prima dell\'avviso, del ' . Carbon::parse($giornoDellInvio)->format('d/m/Y') . '.']);
        }
        // La raccomandata e il fax arrivano dopo essere partiti: un giorno prima della spedizione è un errore di battitura.
        $spedita = (string) ($invio->dati['spedizione']['data'] ?? '');
        if (in_array($mezzo, ['raccomandata', 'fax'], true) && $spedita !== '' && $giorno < $spedita) {
            throw ValidationException::withMessages(['giorno' => 'La consegna non può essere prima della spedizione, del ' . Carbon::parse($spedita)->format('d/m/Y') . '.']);
        }
        $correzione = $invio->esito !== null && ! $ritiro;
        if ($correzione && trim((string) $perche) === '') {
            throw ValidationException::withMessages(['perche' => self::GIA_REGISTRATO]);
        }

        $prima = ['esito' => $invio->esito, 'esito_il' => $invio->esito_il?->format('Y-m-d H:i'), 'ricevuta_il' => $invio->ricevuta_il?->format('Y-m-d')];
        $ricevutaIl = in_array($esitoNuovo->value, RegistroInvio::ESITI_CHE_DANNO_LA_RICEZIONE, true) ? $giorno : null;
        // Ritirata dopo l'avviso di giacenza: il giorno che conta resta quello dell'avviso.
        if ($esitoNuovo === EsitoInvio::CONSEGNATA && $invio->esito === RegistroInvio::ESITO_IN_GIACENZA && $invio->ricevuta_il !== null
            && $invio->ricevuta_il->format('Y-m-d') < $giorno) {
            $ricevutaIl = $invio->ricevuta_il->format('Y-m-d');
        }
        $esitoIl = Carbon::parse($giorno . ' ' . ($ora !== null && preg_match('/^\d{1,2}:\d{2}$/', $ora) === 1 ? $ora : '00:00'), (string) config('app.timezone'));
        $impronta = $prova !== null ? self::improntaDel($prova) : null;

        return DB::transaction(function () use ($assemblea, $invio, $esitoNuovo, $giorno, $ora, $prova, $utente, $perche, $correzione, $ritiro, $prima, $ricevutaIl,
            $esitoIl, $impronta, $ricevuta) {
            // Una PEC rimasta in coda con un esito è partita: il gestore ha risposto.
            if ($invio->stato === RegistroInvio::STATO_IN_CODA) {
                $invio->update(['stato' => RegistroInvio::STATO_INVIATA]);
            }
            $documento = $prova !== null ? $this->lettere->allegaProva($invio, $prova, $utente) : null;
            $invio->refresh();
            $dati = (array) $invio->dati;
            $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento($correzione ? 'esito_corretto' : ($ritiro ? 'ritiro' : 'esito'), $utente) + array_filter([
                'esito' => $esitoNuovo->value, 'giorno' => $giorno, 'ora' => $ora, 'documento_id' => $documento?->id, 'impronta' => $impronta,
                'perche' => $correzione ? trim((string) $perche) : null, 'prima' => $correzione ? $prima : null, 'ricevuta' => $ricevuta,
            ], fn ($v) => $v !== null)];
            if ($dati['mezzo'] ?? null) {
                $dati['spedizione'] ??= ['canale' => $dati['mezzo'], 'data' => $giorno];
            }
            $invio->fill(['esito' => $esitoNuovo->value, 'esito_il' => $esitoIl, 'ricevuta_il' => $ricevutaIl, 'dati' => $dati,
                'stato' => $invio->stato === RegistroInvio::STATO_STAMPATA ? RegistroInvio::STATO_SPEDITA : $invio->stato]);
            $frase = $ritiro ? self::fraseDelRitiro($invio, $giorno, (string) $prima['ricevuta_il'])
                : self::fraseDellEsito($invio, $esitoNuovo, $giorno, $ora) . ($ricevuta !== null ? ', dalla ricevuta del gestore' : '');
            $gesto = $correzione
                ? GestiDelRegistro::esitoCorretto($assemblea, $invio, self::fraseDellaCorrezione($invio, $prima, $esitoNuovo->value, $ricevutaIl ?? $giorno), trim((string) $perche))
                : GestiDelRegistro::esitoRegistrato($assemblea, $invio, $frase);
            // La prova entra nella catena del registro con il suo nome e la sua impronta: un file sostituito dopo si vede.
            if ($documento !== null) {
                $gesto->lotto(['prova' => ['documento_id' => (int) $documento->id, 'nome' => (string) $documento->name, 'impronta' => (string) $impronta]]);
            }
            if ($ricevuta !== null) {
                $gesto->lotto(['ricevuta' => $ricevuta]);
            }
            RegistroModifiche::durante($gesto, fn () => $invio->save());
            // La consegna a mano registrata sulla riga spedisce l'avviso; la PEC rimasta in coda con un esito è partita.
            $this->dopo($assemblea, $utente, in_array($invio->stato, [RegistroInvio::STATO_SPEDITA, RegistroInvio::STATO_INVIATA], true), (string) $invio->tipo);

            return $invio->refresh();
        });
    }

    /**
     * La ricevuta PEC trascinata sulla riga dell'invio (decisione 577): si legge il `daticert.xml` e, se è la ricevuta di consegna di questa PEC a questa
     * persona, l'esito è «Consegnata» con il giorno e l'ora del gestore, e il file resta sulla riga come prova; se è il suo avviso di mancata consegna, l'esito
     * è «Non consegnata», con il file. Se non lo è, niente si scrive e la frase dice perché. Con un esito già registrato con lo stesso giorno e la stessa ora la ricevuta si allega soltanto; con un esito diverso è una correzione.
     */
    public function registraLaRicevuta(Assemblea $assemblea, RegistroInvio $invio, UploadedFile $file, User $utente, ?string $perche = null): RegistroInvio
    {
        $chi = (string) ($invio->dati['persona']['nome'] ?? 'questa persona');
        if ($invio->canale !== RegistroInvio::CANALE_PEC) {
            throw ValidationException::withMessages(['ricevuta' => 'La ricevuta del gestore si trascina su una PEC: l\'avviso di ' . $chi . ' non è partito per PEC.']);
        }
        // I controlli della ricevuta (è una consegna, di questo indirizzo, di questa PEC, non dopo oggi né prima dell'invio) stanno in un posto solo, con le
        // lettere a chi è in ritardo.
        $letta = RicevutaDellInvio::controlla($invio, $file, $invio->tipo === RegistroInvio::TIPO_VERBALE ? 'del verbale' : 'della convocazione');
        $giorno = $letta->quando->format('Y-m-d');
        $ora = $letta->quando->format('H:i');
        // L'avviso di mancata consegna: l'esito «Non consegnata», con il file come prova; la persona si spedisce di nuovo (o si consegna a mano).
        if ($letta->eMancataConsegna()) {
            return $this->registra($assemblea, $invio, EsitoInvio::NON_CONSEGNATA->value, $giorno, $ora, $file, $utente, $perche, $letta->perLaRiga());
        }
        if ($invio->esito === RegistroInvio::ESITO_CONSEGNATA && $invio->ricevuta_il?->format('Y-m-d') === $giorno
            && in_array($invio->esito_il?->format('H:i'), [$ora, '00:00'], true) && trim((string) $perche) === '') {
            // Lo stesso fatto, scritto prima a mano: la ricevuta si allega come prova.
            $this->allegaProva($assemblea, $invio, $file, $utente);

            return $invio->refresh();
        }

        return $this->registra($assemblea, $invio, EsitoInvio::CONSEGNATA->value, $giorno, $ora, $file, $utente, $perche, $letta->perLaRiga());
    }

    /**
     * «Allega la prova» a un invio, senza cambiare l'esito: la ricevuta arrivata dopo, la cartolina. Non si toglie. È una riga del registro delle modifiche
     * sull'assemblea, con il nome e l'impronta del file: «Prova allegata alla raccomandata di Neri Carla: cartolina.jpg, impronta 7f3a…».
     */
    public function allegaProva(Assemblea $assemblea, RegistroInvio $invio, UploadedFile $prova, User $utente): Documento
    {
        $impronta = self::improntaDel($prova);

        return DB::transaction(function () use ($assemblea, $invio, $prova, $utente, $impronta) {
            $documento = $this->lettere->allegaProva($invio, $prova, $utente);
            // Il fatto sulla riga dell'invio resta, segnato come già nel registro: il «Diario» lo mostra una volta sola.
            $invio->refresh();
            $dati = (array) $invio->dati;
            $ultimo = array_key_last((array) ($dati['eventi'] ?? []));
            if ($ultimo !== null && ($dati['eventi'][$ultimo]['evento'] ?? null) === 'prova_allegata') {
                $dati['eventi'][$ultimo]['nel_registro'] = true;
                $invio->update(['dati' => $dati]);
            }
            RegistroModifiche::gesto(GestiDelRegistro::provaAllegata($assemblea, $invio, (string) $documento->name, $impronta)
                ->lotto(['prova' => ['documento_id' => (int) $documento->id, 'nome' => (string) $documento->name, 'impronta' => $impronta]]));

            return $documento;
        });
    }

    /**
     * Il foglio delle consegne a mano firmato: il file una volta, sulla prima riga; ogni riga con il suo giorno, consegnata. Una riga sola nel registro
     * («Consegna a mano registrata: 4 persone, con il foglio firmato»), con le persone fra i soggetti e il giorno di ognuna nel lotto.
     *
     * @param list<array{invio_id: int, giorno: string}> $consegne
     */
    public function foglioFirmato(Assemblea $assemblea, UploadedFile $foglio, array $consegne, User $utente, string $tipo = RegistroInvio::TIPO_CONVOCAZIONE): int
    {
        return $this->consegnaAMano($assemblea, $consegne, $foglio, $utente, $tipo);
    }

    /** @param list<array{invio_id: int, giorno: string}> $consegne */
    private function consegnaAMano(Assemblea $assemblea, array $consegne, ?UploadedFile $foglio, User $utente, string $tipo = RegistroInvio::TIPO_CONVOCAZIONE): int
    {
        $giorni = [];
        foreach ($consegne as $c) {
            $giorni[(int) $c['invio_id']] = (string) $c['giorno'];
        }
        $righe = $this->righeDellAssemblea($assemblea, array_keys($giorni), $tipo);
        foreach ($righe as $r) {
            if (($r->dati['mezzo'] ?? null) !== 'a_mano' || $r->esito !== null) {
                throw ValidationException::withMessages(['consegne' => 'Sul foglio vanno gli avvisi da consegnare a mano non ancora consegnati.']);
            }
            if ($giorni[(int) $r->id] < $r->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d')) {
                throw ValidationException::withMessages(['consegne' => 'Il giorno della consegna non può essere prima dell\'avviso.']);
            }
        }
        $this->soloLettereDiOggi($assemblea, $righe, 'consegne');
        $persone = $righe->pluck('anagrafica_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $gesto = GestiDelRegistro::consegnaAManoRegistrata($assemblea, $righe->count(), $foglio !== null, $persone)
            ->lotto(['consegne' => $righe->map(fn (RegistroInvio $r) => ['invio_id' => (int) $r->id, 'ricevuta_il' => $giorni[(int) $r->id]])->values()->all()]);
        $impronta = $foglio !== null ? self::improntaDel($foglio) : null;

        DB::transaction(function () use ($assemblea, $righe, $giorni, $foglio, $utente, $gesto, $impronta) {
            $documento = $foglio !== null ? $this->lettere->allegaProva($righe->first(), $foglio, $utente) : null;
            if ($documento !== null) {
                $gesto->lotto(['prova' => ['documento_id' => (int) $documento->id, 'nome' => (string) $documento->name, 'impronta' => (string) $impronta]]);
            }
            RegistroModifiche::durante($gesto, function () use ($righe, $giorni, $documento, $utente) {
                foreach ($righe as $r) {
                    $r->refresh();
                    $giorno = $giorni[(int) $r->id];
                    $dati = (array) $r->dati;
                    $dati['spedizione'] = ['canale' => 'a_mano', 'data' => $giorno];
                    if ($documento !== null) {
                        $dati['foglio_documento_id'] = $documento->id;
                    }
                    $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento('consegna_a_mano', $utente) + array_filter(['giorno' => $giorno,
                        'documento_id' => $documento?->id], fn ($v) => $v !== null)];
                    $r->update(['stato' => RegistroInvio::STATO_SPEDITA, 'esito' => RegistroInvio::ESITO_CONSEGNATA,
                        'esito_il' => Carbon::parse($giorno . ' 00:00', (string) config('app.timezone')), 'ricevuta_il' => $giorno, 'dati' => $dati]);
                }
            });
        });
        $this->dopo($assemblea, $utente, true, $tipo);

        return $righe->count();
    }

    /**
     * Si spedisce, o si consegna, la lettera di oggi: una lettera stampata già ristampata (non è l'ultima della persona), stampata prima che cambiasse l'avviso
     * ({@see ElencoDeiConvocati}, `da_ristampare`), o di una persona che non è più fra i convocati, non si registra.
     *
     * @param iterable<RegistroInvio> $righe
     */
    private function soloLettereDiOggi(Assemblea $assemblea, iterable $righe, string $campo): void
    {
        // Le lettere del verbale non si confrontano con l'avviso di oggi: il verbale registrato non cambia.
        $stampate = collect($righe)->filter(fn (RegistroInvio $r) => $r->stato === RegistroInvio::STATO_STAMPATA && $r->tipo === RegistroInvio::TIPO_CONVOCAZIONE);
        if ($stampate->isEmpty()) {
            return;
        }
        $voci = collect($this->elenco->per($assemblea))->keyBy(fn ($v) => (int) $v['anagrafica_id']);
        foreach ($stampate as $r) {
            $v = $voci->get((int) $r->anagrafica_id);
            $chi = (string) ($r->dati['persona']['nome'] ?? 'questa persona');
            if ($v === null || (int) ($v['invio']?->id ?? 0) !== (int) $r->id) {
                throw ValidationException::withMessages([$campo => 'La lettera di ' . $chi . ' è stata ristampata: registra quella nuova.']);
            }
            if (! $v['oggi']) {
                throw ValidationException::withMessages([$campo => 'La lettera di ' . $chi . ' è di una persona che non è più fra i convocati di questa assemblea: non spedirla.']);
            }
            if ($v['da_ristampare']) {
                throw ValidationException::withMessages([$campo => 'La lettera di ' . $chi . ' è di prima: dopo la stampa l\'avviso è cambiato. Ristampala, poi registrala.']);
            }
        }
    }

    /**
     * Dopo il gesto: alla prima spedizione registrata l'assemblea diventa «convocata»; i compiti della convocazione si rifanno. Per una riga del verbale si
     * rifanno i compiti del verbale («Trasmettere il verbale»), non lo stato della convocazione.
     */
    private function dopo(Assemblea $assemblea, ?User $utente, bool $spedita, string $tipo = RegistroInvio::TIPO_CONVOCAZIONE): void
    {
        if ($tipo === RegistroInvio::TIPO_VERBALE) {
            app(\App\Services\Assemblee\Verbale\CompitiDelVerbale::class)->sincronizza($assemblea);

            return;
        }
        app(EmissioneDelleConvocazioni::class)->dopoLaSpedizione($assemblea, $utente, $spedita);
    }

    /** L'impronta SHA-256 di un file caricato, letta prima di archiviarlo. */
    public static function improntaDel(UploadedFile $file): string
    {
        return (string) hash_file('sha256', (string) $file->getRealPath());
    }

    /** «Raccomandata a Neri Carla: ritirata l'08/12; conta l'avviso del 07/12». */
    private static function fraseDelRitiro(RegistroInvio $invio, string $giorno, string $giacenza): string
    {
        $chi = (string) ($invio->dati['persona']['nome'] ?? 'la persona');

        return 'Raccomandata a ' . $chi . ': ritirata ' . self::ilGiorno(Carbon::parse($giorno)->format('d/m')) . '; conta l\'avviso del '
            . Carbon::parse($giacenza)->format('d/m');
    }

    /** «il 10/12», «l'08/12», «l'11/12»: l'articolo come si legge il numero (anche con l'anno: «l'11/12/2026»). */
    public static function ilGiorno(string $giornoEMese): string
    {
        return in_array(substr($giornoEMese, 0, 2), ['01', '08', '11'], true) ? 'l\'' . $giornoEMese : 'il ' . $giornoEMese;
    }

    /**
     * «entro il 10/12», «entro l'11/12»: la funzione sola con cui il passo, i compiti, i promemoria e le stampe della convocazione scrivono un termine
     * (B5, simulazione del 21/11): con i giorni 1, 8 e 11 l'articolo si elide, «entro il 11/11» non si scrive.
     *
     * @param \DateTimeInterface|string $giorno una data, o il giorno e mese già scritti
     */
    public static function entro(\DateTimeInterface|string $giorno, string $formato = 'd/m'): string
    {
        return 'entro ' . self::ilGiorno($giorno instanceof \DateTimeInterface ? $giorno->format($formato) : $giorno);
    }

    /** La prova di un invio da mostrare: l'ultima allegata, o il foglio firmato a cui la riga rimanda. */
    public static function prova(RegistroInvio $invio, Collection $documentiDellInvio, ?Documento $foglio): ?Documento
    {
        $conservato = (int) ($invio->dati['documento_id'] ?? 0);
        $prove = $documentiDellInvio->filter(fn (Documento $d) => (int) $d->id !== $conservato)->sortByDesc('id');

        return $prove->first() ?? $foglio;
    }

    /**
     * Le righe chieste, tutte invii della convocazione di questa assemblea: altrimenti 404 per tutta la richiesta.
     *
     * @param list<int> $ids
     * @return Collection<int, RegistroInvio>
     */
    public function righeDellAssemblea(Assemblea $assemblea, array $ids, string $tipo = RegistroInvio::TIPO_CONVOCAZIONE): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        abort_if($ids === [], 404);
        $righe = RegistroInvio::query()->whereIn('id', $ids)->where('condominio_id', $assemblea->condominio_id)
            ->where('documentabile_type', $assemblea->getMorphClass())->where('documentabile_id', $assemblea->id)
            ->where('tipo', $tipo)->orderBy('id')->get();
        abort_unless($righe->count() === count($ids), 404);

        return $righe;
    }

    /** «Raccomandata a Neri Carla: in giacenza, avviso del 07/12»; «PEC a Rossi Mario: consegnata il 03/12 alle 10:14». */
    public static function fraseDellEsito(RegistroInvio $invio, EsitoInvio $esito, string $giorno, ?string $ora): string
    {
        $chi = (string) ($invio->dati['persona']['nome'] ?? $invio->anagrafica?->nome ?? 'la persona');
        $mezzo = ['pec' => 'PEC', 'raccomandata' => 'Raccomandata', 'a_mano' => 'Avviso a mano', 'fax' => 'Fax'][$invio->dati['mezzo'] ?? ''] ?? 'Avviso';
        $g = Carbon::parse($giorno)->format('d/m');
        // Il verbale: «Verbale n. 42, PEC a Galli Franco: consegnata il 15/12 alle 21:40».
        if ($invio->tipo === RegistroInvio::TIPO_VERBALE) {
            $mezzo = 'Verbale n. ' . ($invio->dati['numero_verbale'] ?? '') . ', ' . match ($invio->dati['mezzo'] ?? '') {
                'a_mano' => 'consegna a mano',
                'pec' => 'PEC',
                default => mb_strtolower($mezzo),
            };
        }

        return $mezzo . ' a ' . $chi . ': ' . match ($esito) {
            EsitoInvio::IN_GIACENZA => 'in giacenza, avviso del ' . $g,
            EsitoInvio::CONSEGNATA => 'consegnata il ' . $g . ($ora !== null && $ora !== '' ? ' alle ' . $ora : ''),
            EsitoInvio::RIFIUTATA => 'rifiutata il ' . $g,
            EsitoInvio::NON_CONSEGNATA => 'non consegnata (' . $g . ')',
        };
    }

    /** «Ricevuta di Neri Carla corretta: 10/12 → 09/12»; «Esito di Neri Carla corretto: in giacenza → consegnata». */
    private static function fraseDellaCorrezione(RegistroInvio $invio, array $prima, string $esito, string $giorno): string
    {
        $chi = (string) ($invio->dati['persona']['nome'] ?? 'la persona');
        $parola = fn (?string $e) => mb_strtolower((string) EsitoInvio::parolaDi($e));
        if (($prima['esito'] ?? null) === $esito && ($prima['ricevuta_il'] ?? null) !== null) {
            return 'Ricevuta di ' . $chi . ' corretta: ' . Carbon::parse($prima['ricevuta_il'])->format('d/m') . ' → ' . Carbon::parse($giorno)->format('d/m');
        }

        return 'Esito di ' . $chi . ' corretto: ' . $parola($prima['esito'] ?? null) . ' → ' . $parola($esito) . ' (' . Carbon::parse($giorno)->format('d/m') . ')';
    }

    /** @return array{evento: string, il: string, giorno: string, da: ?int, da_nome: ?string} */
    private static function evento(string $evento, ?User $utente): array
    {
        return ['evento' => $evento, 'il' => now()->toIso8601String(), 'giorno' => DateHelper::oggiUtente(), 'da' => $utente?->id, 'da_nome' => $utente?->name];
    }
}
