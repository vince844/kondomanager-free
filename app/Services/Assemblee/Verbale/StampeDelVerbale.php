<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\EsitoSeduta;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\StatoVerbale;
use App\Helpers\DateHelper;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Sala\ProspettoDellaSala;
use App\Services\Assemblee\Sala\StatiDellaSala;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Carbon\Carbon;
use Mpdf\Output\Destination;

/**
 * Le stampe del verbale (§6 del progetto del verbale), **sul modello comune delle stampe del condominio**: ogni vista estende `pdf.base`, ogni stampa passa il
 * gruppo di carta «Rendiconto e assemblea», e la testata (lo studio, la carta intestata o il condominio) la decide `pdf.base`; nessuna vista scrive una
 * testata a mano. Su ogni pagina il titolo della stampa (`titolo_stampa`, nella testata comune) dice che cosa è la pagina: una pagina staccata si riconosce.
 *
 * - **Il verbale** ({@see bozza()}, {@see perIlLibro()}): il testo dalla struttura validata, che entra nel PDF da {@see TestoDelVerbaleInPdf}; **l'elenco in
 *   calce** con le presenze e i voti della riunione (lo stesso pezzo di «Le presenze e i voti», {@see ProspettoDellaSala::dellaSeduta()}); le firme del
 *   presidente e del segretario; la versione e l'impronta del testo. La pagina per il libro di carta (decisione 595 (2)) ha in più il registro, «Pagina del
 *   libro n. ____» da scrivere a mano e lo spazio per la firma a cavallo; registrata e poi annullata, dice quando e perché.
 * - **Il registro dei verbali** e quello di nomina e revoca ({@see registro()}), e la pagina dell'annotazione di una seduta deserta ({@see annotazione()}).
 * - **La lettera della trasmissione** per persona ({@see lettera()}); la copia sottoscritta la segue, unita dopo.
 * - **La prova della trasmissione** ({@see prova()}): per destinatario il perché, il mezzo, la spedizione, l'esito, la prova, i trenta giorni.
 */
final class StampeDelVerbale
{
    /** Lo spazio per il numero della pagina del libro, da scrivere a mano. */
    public const PAGINA_DEL_LIBRO = 'Pagina del libro n. __________';

    public function __construct(private readonly PdfService $pdf) {}

    /** La bozza da firmare: il testo intero di adesso, con la versione e l'impronta su ogni pagina e in fondo. */
    public function bozza(VerbaleDellAssemblea $verbale, int $versione, string $impronta): string
    {
        return $this->genera('pdf.gestionale.assemblea_verbale', $this->datiDelVerbale($verbale, false, $versione, $impronta));
    }

    /**
     * La pagina per il libro di carta di un verbale registrato (decisione 595 (2)): il testo firmato con l'elenco in calce, in testa il registro e «Pagina
     * del libro n. ____» da scrivere a mano, in fondo lo spazio per la firma a cavallo. Una registrazione annullata ha la sua pagina, con il perché.
     */
    public function perIlLibro(VerbaleDellAssemblea $verbale): string
    {
        return $this->genera('pdf.gestionale.assemblea_verbale', $this->datiDelVerbale($verbale, true));
    }

    /**
     * I dati della vista del verbale. Senza versione e impronta (la pagina per il libro) valgono quelle del testo stampato: l'impronta del suo documento, e il
     * numero della bozza firmata se è lo stesso testo, altrimenti dell'ultima versione.
     *
     * @return array<string, mixed>
     */
    public function datiDelVerbale(VerbaleDellAssemblea $verbale, bool $perIlLibro = false, ?int $versione = null, ?string $impronta = null): array
    {
        $verbale->loadMissing(['assemblea.condominio', 'seduta', 'sostituisce']);
        $a = $verbale->assemblea;
        $seduta = $verbale->seduta;
        $documento = StrutturaDelVerbale::valida((array) (ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento')
            ?? ['type' => 'doc']));
        $impronta ??= StrutturaDelVerbale::impronta($documento);
        $versione ??= ($verbale->stampata['impronta_testo'] ?? null) === $impronta ? (int) $verbale->stampata['versione']
            : (int) ($verbale->versioni()->where('parte', 'intero')->where('in_attesa', false)->reorder()->orderByDesc('numero')->value('numero') ?? 0);
        $numero = $verbale->numero;
        $breve = substr($impronta, 0, 8);
        $assemblea = self::assembleaInParole($verbale);
        $registro = RegistroDeiVerbali::perimetro($a->condominio, (string) ($verbale->registro ?: VerbaliDellAssemblea::registroDi($a)));

        return [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            // Firmano il presidente e il segretario: la firma dello studio non c'entra.
            'senza_firma' => true,
            'condominio' => $a->condominio,
            'verbale' => $verbale,
            'per_il_libro' => $perIlLibro,
            'titolo' => $perIlLibro && $numero !== null ? 'Verbale n. ' . $numero . ' · ' . mb_strtolower($assemblea['breve'])
                : ($numero !== null ? 'Verbale n. ' . $numero : 'Bozza del verbale da firmare'),
            'titolo_stampa' => $perIlLibro ? self::PAGINA_DEL_LIBRO . ' · Verbale n. ' . $numero . ' · impronta ' . $breve
                : ($numero !== null ? 'Verbale n. ' . $numero . ' · versione ' . $versione . ' · impronta ' . $breve
                    : 'Bozza da firmare · versione ' . $versione . ' · impronta ' . $breve),
            'assemblea' => $assemblea['lunga'],
            'condominio_nome' => TestiLettere::condominioConNome((string) $a->condominio->nome),
            'registro' => $registro['nome'],
            'registro_del_libro' => self::registroDelLibro($registro['nome']),
            'documento' => $documento,
            'presidente' => (string) ($seduta?->presidente['nome'] ?? ''),
            'segretario' => (string) ($seduta?->segretario['nome'] ?? ''),
            'elenco' => $seduta === null ? null : app(ProspettoDellaSala::class)->dellaSeduta($a, (int) $seduta->id),
            'registrazione' => $numero === null ? null : array_filter([
                'sottoscritto_il' => $verbale->sottoscritto_il?->format('d/m/Y'),
                'registrato_il' => $verbale->registrato_il?->copy()->setTimezone(ParoleDelVerbale::fuso())->format('d/m/Y'),
                'sostituisce' => $verbale->sostituisce?->numero,
            ], fn ($v) => $v !== null),
            'annullato' => $verbale->stato === StatoVerbale::ANNULLATO && $verbale->annullato_il !== null ? [
                'il' => $verbale->annullato_il->copy()->setTimezone(ParoleDelVerbale::fuso())->format('d/m/Y'),
                'motivo' => (string) $verbale->annullato_motivo,
            ] : null,
            'versione' => $versione,
            'impronta' => $impronta,
        ];
    }

    /**
     * La lettera della trasmissione di una persona (senza la copia, che si unisce dopo): il mezzo e il destinatario, l'oggetto, il verbale con il numero,
     * i punti per cui lo riceve come assente con i trenta giorni (art. 1137 c. 2 c.c.), in videoconferenza la trasmissione a tutti (art. 66 disp. att. c.c.).
     *
     * @param array<string, mixed> $destinatario `nome`, `unita`, `righe`; facoltativi `mezzo`, `mezzo_parola`, `indirizzo`
     */
    public function lettera(VerbaleDellAssemblea $verbale, array $destinatario): string
    {
        $verbale->loadMissing(['assemblea.condominio', 'seduta']);
        $a = $verbale->assemblea;
        $assemblea = self::assembleaInParole($verbale);
        $tutti = in_array($a->modalita, [ModalitaAssemblea::VIDEOCONFERENZA, ModalitaAssemblea::MISTA], true);
        $mezzo = (string) ($destinatario['mezzo'] ?? '');

        return $this->genera('pdf.gestionale.assemblea_trasmissione', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $a->condominio,
            'verbale' => $verbale,
            'destinatario' => $destinatario + ['indirizzo' => null, 'mezzo_parola' => null],
            // L'indirizzo di casa si scrive per la raccomandata e la consegna a mano; la PEC ha il suo nel messaggio.
            'indirizzo' => in_array($mezzo, ['raccomandata', 'a_mano'], true) ? ($destinatario['indirizzo'] ?? null) : null,
            'assemblea' => $assemblea['lunga'],
            'oggetto' => 'Oggetto: trasmissione del verbale n. ' . $verbale->numero . ' dell\'' . mb_strtolower($assemblea['breve']),
            'corpo' => 'le trasmetto il verbale n. ' . $verbale->numero . ' dell\'' . mb_strtolower($assemblea['breve']) . ' dei condòmini del '
                . TestiLettere::condominioConNome((string) $a->condominio->nome) . ($mezzo === 'pec'
                    // Nella PEC la copia sottoscritta è un allegato a parte, com'è (anche il .p7m): non segue la lettera nello stesso PDF.
                    ? ', nella copia sottoscritta allegata a questo messaggio.' : ', nella copia sottoscritta che segue questa lettera.'),
            'tutti' => $tutti ? 'L\'assemblea si è tenuta anche in videoconferenza: il verbale si trasmette a tutti i condòmini (art. 66 disp. att. c.c.).' : null,
            'giorno' => Carbon::parse(DateHelper::oggiUtente())->format('d/m/Y'),
            'amministratore' => app(RegistroDiNominaERevoca::class)->amministratoreDiOggi((int) $a->condominio_id),
            'titolo_stampa' => 'Trasmissione del verbale n. ' . $verbale->numero,
        ]);
    }

    /**
     * Il registro dei verbali (e quello di nomina e revoca), con il punto di partenza, la colonna «Nel libro di carta» e l'impronta della stampa (lo SHA-256
     * delle righe stampate: le stesse righe, la stessa impronta).
     *
     * @param array{chiave: string, nome: string, scala_id: ?int, palazzina_id: ?int} $perimetro
     * @param list<array<string, mixed>> $righe
     * @param list<array<string, mixed>> $nomine
     */
    public function registro(Condominio $condominio, array $perimetro, array $righe, array $nomine, ?int $anno): string
    {
        $impronta = hash('sha256', (string) json_encode([$perimetro, $righe, $nomine], JSON_UNESCAPED_UNICODE));
        $partenza = app(RegistroDeiVerbali::class)->partenza($condominio, (string) $perimetro['chiave']);

        return $this->genera('pdf.gestionale.assemblea_registro_verbali', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $condominio,
            'perimetro' => $perimetro,
            'righe' => array_map(fn (array $r) => self::rigaDelRegistro($r), $righe),
            'nomine' => array_map(fn (array $n) => self::rigaDiNomina($n), $nomine),
            'anno' => $anno,
            'partenza' => match ($partenza['scelta'] ?? null) {
                'libro' => 'Il registro continua il libro di carta: ultimo numero ' . $partenza['ultimo']
                    . (filled($partenza['del'] ?? null) ? ' del ' . Carbon::parse((string) $partenza['del'])->format('d/m/Y') : '') . '.',
                'da_uno' => 'Il registro comincia dal n. 1.',
                default => null,
            },
            'impronta' => $impronta,
            'stampato_il' => DateHelper::adessoUtente()->format('d/m/Y \a\l\l\e H:i'),
            'titolo_stampa' => $perimetro['nome'] . ($anno !== null ? ' · ' . $anno : ''),
        ]);
    }

    /**
     * La pagina per il libro di carta di una seduta deserta (decisione 595 (2), scena 8): «Annotazione · mancata costituzione», con il giorno, l'ora, la
     * convocazione, l'appello se la seduta era stata aperta, la prosecuzione dell'avviso; l'amministratore e lo spazio per la firma a cavallo.
     */
    public function annotazione(SedutaDellAssemblea $seduta): string
    {
        $seduta->loadMissing('assemblea.condominio');
        $a = $seduta->assemblea;
        $frasi = ['Il giorno ' . ParoleDelVerbale::giorno($seduta->fissata_il->format('Y-m-d')) . ', alle ore ' . $seduta->fissata_il->format('H:i') . ', in '
            . StatiDellaSala::convocazione($seduta) . ', l\'assemblea ' . mb_strtolower((string) $a->tipo?->parola()) . ' dei condòmini del '
            . TestiLettere::condominioConNome((string) $a->condominio->nome) . ' non si è costituita'
            . ($seduta->chiusa_il !== null ? ' (dichiarata deserta alle ore ' . ParoleDelVerbale::ora($seduta->chiusa_il) . ')' : '') . '.'];
        $c = LetturaDellaSala::costituzioneDellaSeduta($seduta, collect());
        if ($c !== null) {
            $frasi[] = 'All\'appello risultavano presenti, di persona o per delega, ' . ParoleDelVerbale::condomini((int) $c['teste']) . ' su ' . $c['totale_teste']
                . ', per ' . ParoleDelVerbale::millesimi((int) $c['valore']) . ' millesimi su ' . ParoleDelVerbale::totale((int) $c['totale_valore']) . '.';
        }
        $seconda = $seduta->convocazione?->value === 'prima'
            ? $a->sedute()->where('convocazione', 'seconda')->where('numero', 1)->first() : null;
        if ($seconda !== null && $seconda->esito !== EsitoSeduta::DESERTA) {
            $frasi[] = 'L\'assemblea prosegue in seconda convocazione il ' . ParoleDelVerbale::giorno($seconda->fissata_il->format('Y-m-d')) . ' alle ore '
                . $seconda->fissata_il->format('H:i') . ', come nell\'avviso.';
        }
        $giorno = $seduta->fissata_il->format('d/m/Y');

        return $this->genera('pdf.gestionale.assemblea_registro_verbali', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'senza_firma' => true,
            'condominio' => $a->condominio,
            'perimetro' => RegistroDeiVerbali::perimetro($a->condominio, VerbaliDellAssemblea::registroDi($a)),
            'righe' => [],
            'nomine' => [],
            'anno' => null,
            'partenza' => null,
            'impronta' => hash('sha256', implode("\n", $frasi)),
            'stampato_il' => DateHelper::adessoUtente()->format('d/m/Y \a\l\l\e H:i'),
            'annotazione' => ['titolo' => 'Annotazione · mancata costituzione', 'frasi' => $frasi,
                'registro' => self::registroDelLibro(RegistroDeiVerbali::perimetro($a->condominio, VerbaliDellAssemblea::registroDi($a))['nome']),
                'amministratore' => app(RegistroDiNominaERevoca::class)->amministratoreDiOggi((int) $a->condominio_id)],
            'titolo_stampa' => self::PAGINA_DEL_LIBRO . ' · Annotazione del ' . $giorno,
        ]);
    }

    /**
     * La prova della trasmissione del verbale: per ogni destinatario il perché, il mezzo, la spedizione, l'esito, la prova con l'inizio della sua impronta, i
     * trenta giorni; chi manca; l'impronta della stampa (lo SHA-256 delle righe stampate).
     *
     * @param array{destinatari: list<array<string, mixed>>} $dati
     */
    public function prova(VerbaleDellAssemblea $verbale, array $dati): string
    {
        $verbale->loadMissing(['assemblea.condominio']);
        $ids = array_values(array_filter(array_map(fn ($d) => $d['invio']['id'] ?? null, $dati['destinatari'])));
        $invii = RegistroInvio::query()->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $documenti = Documento::query()->where('documentable_type', RegistroInvio::class)->whereIn('documentable_id', $ids ?: [0])->get()
            ->groupBy(fn (Documento $d) => (int) $d->documentable_id);
        $righe = [];
        $mancano = [];
        foreach ($dati['destinatari'] as $d) {
            $invio = isset($d['invio']['id']) ? $invii->get((int) $d['invio']['id']) : null;
            $riga = [
                'persona' => (string) $d['nome'],
                'unita' => (string) ($d['unita'] ?? ''),
                'perche' => (string) ($d['perche'] ?? ''),
                'mezzo' => (string) ($d['mezzo']['parola'] ?? ''),
                'spedizione' => self::spedizione($invio),
                'esito' => self::esito($d['invio'] ?? null),
                'prova' => $invio === null ? '—' : self::provaInParole(ConsegneDellaConvocazione::prova($invio, $documenti->get((int) $invio->id) ?? collect(), null)),
                'termini' => self::termini((array) ($d['termini'] ?? [])),
            ];
            $righe[] = $riga;
            if (($d['invio']['ricevuta_il'] ?? null) === null) {
                $mancano[] = $riga['persona'] . ': ' . mb_strtolower($riga['esito']) . ' (' . $riga['mezzo'] . ')';
            }
        }

        return $this->genera('pdf.gestionale.assemblea_prova_trasmissione', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $verbale->assemblea->condominio,
            'verbale' => $verbale,
            'titolo' => 'Prova della trasmissione del verbale n. ' . $verbale->numero,
            'assemblea' => self::assembleaInParole($verbale)['lunga'],
            'righe' => $righe,
            'mancano' => $mancano,
            'nota' => 'Il verbale va a chi era assente su un punto (in videoconferenza a tutti i condòmini), con il mezzo e la prova della convocazione. Per chi '
                . 'era assente i trenta giorni per impugnare (art. 1137 c. 2 c.c.) decorrono dal giorno in cui lo riceve (la raccomandata in giacenza: dal ritiro, '
                . 'o dalla compiuta giacenza dieci giorni dopo l\'avviso se non è ritirata), calcolati in modo prudente: dal giorno dopo, senza agosto, con l\'ultimo '
                . 'giorno festivo spostato al primo lavorativo. Ogni consegna è anche una riga del registro delle modifiche.',
            'impronta' => hash('sha256', (string) json_encode($righe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'stampata_il' => DateHelper::adessoUtente()->format('d/m/Y \a\l\l\e H:i'),
            'titolo_stampa' => 'Prova della trasmissione del verbale n. ' . $verbale->numero,
        ], ['orientation' => 'L']);
    }

    /**
     * L'assemblea in parole: `breve` «Assemblea ordinaria del 15/12/2026» (con la scala o la palazzina), `lunga` con la convocazione («…, seconda
     * convocazione»; «…, prosecuzione»).
     *
     * @return array{breve: string, lunga: string}
     */
    private static function assembleaInParole(VerbaleDellAssemblea $verbale): array
    {
        $a = $verbale->assemblea;
        $seduta = $verbale->seduta;
        $perimetro = match ($a->ambito) {
            AmbitoAssemblea::SCALA => $a->scala?->name !== null ? ' della scala ' . $a->scala->name : '',
            AmbitoAssemblea::PALAZZINA => $a->palazzina?->name !== null ? ' della palazzina ' . $a->palazzina->name : '',
            default => '',
        };
        $giorno = $seduta?->fissata_il?->format('d/m/Y') ?? $a->data?->format('d/m/Y');
        $breve = 'Assemblea ' . mb_strtolower((string) ($a->tipo?->parola() ?? '')) . $perimetro . ($giorno !== null ? ' del ' . $giorno : '');
        $convocazione = $seduta === null ? null : ((int) $seduta->numero > 1 ? 'prosecuzione' : ($seduta->convocazione?->value === 'seconda'
            ? 'seconda convocazione' : 'prima convocazione'));

        return ['breve' => $breve, 'lunga' => $breve . ($convocazione !== null ? ', ' . $convocazione : '')];
    }

    /**
     * Il registro a cui la pagina del libro appartiene (decisione 598): «Registro dei verbali delle assemblee di Via Roma 12», «… della scala B di Via Roma 12».
     */
    private static function registroDelLibro(string $registro): string
    {
        return (string) preg_replace('/^Registro dei verbali /u', 'Registro dei verbali delle assemblee ', $registro, 1);
    }

    /** Una riga del registro come la stampa: il numero, la data, l'assemblea, il contenuto, lo stato, il libro di carta. @param array<string, mixed> $r */
    private static function rigaDelRegistro(array $r): array
    {
        $giorno = fn (?string $g) => $g === null ? '' : Carbon::parse($g)->format('d/m/Y');
        $stato = match ($r['tipo']) {
            'verbale' => isset($r['annullato']['il'])
                ? 'Annullato il ' . Carbon::parse((string) $r['annullato']['il'])->setTimezone(ParoleDelVerbale::fuso())->format('d/m/Y') . ': ' . $r['annullato']['motivo']
                : 'Sottoscritto il ' . $giorno($r['sottoscritto_il'] ?? null) . (($r['sostituisce'] ?? null) !== null ? ' · sostituisce il n. ' . $r['sostituisce'] : ''),
            'a_mano' => 'A mano' . (($r['verbale_caricato'] ?? false) ? ' · verbale caricato' : ''),
            default => (string) $r['parola'],
        };

        return [
            'numero' => $r['numero'] === null ? '—' : (string) $r['numero'],
            'giorno' => $giorno($r['giorno'] ?? null),
            'assemblea' => (string) $r['assemblea']['parola'],
            'contenuto' => $r['tipo'] === 'annotazione' ? '—' : (string) $r['contenuto'],
            'stato' => $stato,
            'annullato' => isset($r['annullato']['il']),
            'libro' => $r['tipo'] === 'a_mano' ? '—' : (($r['nel_libro_il'] ?? null) !== null ? 'Incollata nel libro il ' . $giorno($r['nel_libro_il']) : 'Da incollare'),
        ];
    }

    /** Una riga del registro di nomina e revoca come la stampa. @param array<string, mixed> $n */
    private static function rigaDiNomina(array $n): array
    {
        return [
            'dal' => Carbon::parse((string) $n['dal'])->format('d/m/Y'),
            'evento' => (string) $n['evento_parola'],
            'amministratore' => (string) $n['amministratore'],
            'da_dove' => match ($n['da_dove'] ?? null) {
                'verbale' => 'Verbale registrato' . (($n['verbale']['numero'] ?? null) !== null ? ' n. ' . $n['verbale']['numero'] : ''),
                'decreto' => 'Decreto',
                default => 'Delibera su carta',
            },
            'estremi' => trim((string) ($n['estremi'] ?? '') . (filled($n['nota'] ?? null) ? ' · ' . $n['nota'] : '')),
            // Una riga annullata resta nel registro, con il giorno e il perché.
            'annullata' => ($n['stato'] ?? null) === 'annullata' ? 'Annullata' . (($n['annullata_il'] ?? null) !== null ? ' il '
                . Carbon::parse((string) $n['annullata_il'])->format('d/m/Y') : '') . (filled($n['annullata_motivo'] ?? null) ? ': ' . $n['annullata_motivo'] : '') : null,
        ];
    }

    /** La spedizione di un invio in parole. */
    private static function spedizione(?RegistroInvio $invio): string
    {
        $fuso = ParoleDelVerbale::fuso();

        return match (true) {
            $invio === null => '—',
            $invio->canale === RegistroInvio::CANALE_PEC => 'partita il ' . $invio->emesso_il->copy()->setTimezone($fuso)->format('d/m/Y \a\l\l\e H:i')
                . ($invio->stato === RegistroInvio::STATO_NON_PARTITA ? ', non accettata' : ''),
            filled($invio->dati['spedizione']['data'] ?? null) => Carbon::parse((string) $invio->dati['spedizione']['data'])->format('d/m/Y')
                . (filled($invio->dati['spedizione']['numero'] ?? null) ? ', n. ' . $invio->dati['spedizione']['numero'] : ''),
            default => 'stampata il ' . $invio->emesso_il->copy()->setTimezone($fuso)->format('d/m/Y') . ', non ancora spedita',
        };
    }

    /** L'esito di un invio: «Consegnata il 18/12/2026», «In attesa dell'esito», «Da spedire». @param ?array<string, mixed> $invio */
    private static function esito(?array $invio): string
    {
        if ($invio === null) {
            return 'Da spedire';
        }
        if (($invio['esito'] ?? null) === null) {
            return ($invio['spedito'] ?? false) ? 'In attesa dell\'esito' : 'Da spedire';
        }
        $giorno = $invio['ricevuta_il'] ?? (isset($invio['esito_il']) ? Carbon::parse((string) $invio['esito_il'])->setTimezone(ParoleDelVerbale::fuso())->format('Y-m-d') : null);

        return (string) $invio['esito_parola'] . ($giorno !== null ? ' il ' . Carbon::parse((string) $giorno)->format('d/m/Y') : '');
    }

    /** I trenta giorni di una persona: «punti 1 e 2: fino al 18/01/2027», «dalla consegna». @param list<array<string, mixed>> $termini */
    private static function termini(array $termini): string
    {
        $parti = [];
        foreach ($termini as $t) {
            $quando = $t['fino_al'] !== null ? 'fino al ' . Carbon::parse((string) $t['fino_al'])->format('d/m/Y')
                . DestinatariDelVerbale::daQuando($t['dal_come'] ?? null, $t['dal'] ?? null) : 'dalla consegna';
            $parti[] = ParoleDelVerbale::punti(array_map('intval', (array) $t['punti'])) . ': ' . $quando;
        }

        return $parti === [] ? '—' : implode('; ', $parti);
    }

    /** La prova di un esito: il nome del file e l'inizio della sua impronta. */
    private static function provaInParole(?Documento $documento): string
    {
        if ($documento === null) {
            return '—';
        }
        $contenuto = app(\App\Services\Documenti\ArchivioDocumenti::class)->leggi((string) $documento->path);

        return $documento->name . ($contenuto !== null ? ' · ' . substr(hash('sha256', $contenuto), 0, 12) : '');
    }

    /** @param array<string, mixed> $dati @param array<string, mixed> $config */
    private function genera(string $vista, array $dati, array $config = []): string
    {
        return $this->pdf->generate($vista, $dati, $config)->Output('', Destination::STRING_RETURN);
    }
}
