<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Posta\PecDelloStudio;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * Lo stato della convocazione di un'assemblea (§3 P13 del progetto della tappa 2): il passo «Convocazione» della pagina (prop `convocazione`), nella forma
 * `SchedaDellaConvocazione` di `components/assemblee/convocazione/tipi.ts`. La riga del passo sulla strada («23 su 27 raggiunte») la scrive
 * {@see \App\Services\Assemblee\PassiDellAssemblea}.
 *
 * Gli stati di una persona sono le parole della bozza approvata: «Consegnata», «In giacenza», «Spedita», «Da consegnare», «Non convocabile», e quelle che la
 * bozza non mostra: «Rifiutata», «Non consegnata», «Da spedire», «Stampata», «Non partita», «Da verificare» (una PEC rimasta in coda), «Da ristampare» (la
 * lettera stampata prima che cambiassero le date o i punti).
 *
 * «Stampa le lettere (N)» è un pulsante solo, con tutte le lettere su carta (raccomandate, a mano, fax) da stampare o da ristampare; il foglio delle firme
 * delle consegne a mano resta a parte. Lo stato della convocazione segue la regola di «spedito» ({@see ElencoDeiConvocati::spedito()}).
 */
class StatoDellaConvocazione
{
    /** Lo stato di una persona: la parola e il colore. */
    private const STATI = [
        'consegnata' => ['Consegnata', 'em'],
        'in_giacenza' => ['In giacenza', 'am'],
        'spedita' => ['Spedita', 'sk'],
        'da_consegnare' => ['Da consegnare', 'sl'],
        'non_convocabile' => ['Non convocabile', 'ro'],
        'rifiutata' => ['Rifiutata', 'ro'],
        'non_consegnata' => ['Non consegnata', 'ro'],
        'da_spedire' => ['Da spedire', 'sl'],
        'stampata' => ['Stampata, da spedire', 'sl'],
        'non_partita' => ['Non partita', 'ro'],
        'da_verificare' => ['Da verificare', 'am'],
        'da_ristampare' => ['Da ristampare', 'am'],
        'non_piu_convocato' => ['Non più convocato', 'sl'],
    ];

    public function __construct(
        private readonly ElencoDeiConvocati $elenco,
        private readonly TerminiDellaConvocazione $termini,
        private readonly PecDelloStudio $pec,
        private readonly NuovoTitolareNonConvocato $nuovi,
    ) {}

    /** Il passo «Convocazione» (prop `convocazione`); vuoto finché l'assemblea non ha la prima convocazione. @return array<string, mixed> */
    public function perLaPagina(Assemblea $assemblea): array
    {
        if ($this->termini->primaConvocazione($assemblea) === null || $assemblea->eAMano()) {
            return [];
        }
        $gestisce = AccessoAlleAssemblee::gestisce(auth()->user());
        $aperta = in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true);
        $voci = $this->elenco->per($assemblea);
        $conteggi = self::perMezzo($voci);
        $documenti = Documento::query()->where('documentable_type', RegistroInvio::class)
            ->whereIn('documentable_id', collect($voci)->flatMap(fn ($v) => array_map(fn ($i) => (int) $i->id, $v['invii']))->all() ?: [0])->get();
        $perInvio = $documenti->groupBy(fn (Documento $d) => (int) $d->documentable_id);
        $fogli = $documenti->keyBy('id');
        $ultimo = $this->termini->ultimoGiorno($assemblea);

        $righe = [];
        foreach ($voci as $v) {
            $righe[] = $this->riga($assemblea, $v, $perInvio, $fogli, $ultimo?->format('Y-m-d'), $gestisce && $aperta);
        }
        $daStampare = array_filter($voci, fn ($v) => ElencoDeiConvocati::daStampareLaVoce($v));
        $daMandare = array_filter($voci, fn ($v) => (! $v['vale'] || $v['da_ristampare']) && $v['oggi'] && $v['mezzo']['valido'] && $v['mezzo']['mezzo'] === 'pec');
        $pecNonComunicate = count(array_filter($voci, fn ($v) => ! $v['vale'] && ($v['mezzo']['pec_non_comunicata'] ?? false)));
        // Senza il luogo o la piattaforma l'avviso è incompleto (art. 66 c. 3): i due pulsanti sono spenti e dicono che cosa manca.
        $mancaAllAvviso = $aperta ? EmissioneDelleConvocazioni::cheCosaMancaAllAvviso($assemblea) : null;
        $perchePec = ! $aperta ? 'L\'assemblea non è più da convocare.' : ($this->pec->perche() ?? (! $gestisce ? 'Serve «Gestisce le assemblee».' : $mancaAllAvviso));
        $perchePecMostrato = $perchePec === PecDelloStudio::NON_IMPOSTATA && $daMandare === [] ? null : $perchePec;
        $percheLettere = ! $aperta ? 'L\'assemblea non è più da convocare.' : (! $gestisce ? 'Serve «Gestisce le assemblee».' : $mancaAllAvviso);
        $uscite = array_filter($voci, fn ($v) => $v['invio'] !== null);
        // Le consegne a mano da registrare con il foglio firmato: non le lettere di prima, che si ristampano.
        $aMano = count(array_filter($voci, fn ($v) => ($v['invio']?->dati['mezzo'] ?? null) === 'a_mano' && $v['invio']?->esito === null && ! $v['da_ristampare']
            && ! ($v['non_piu_convocato'] ?? false)));

        return [
            'stato' => match (true) {
                $assemblea->stato === StatoAssemblea::ANNULLATA => 'annullata',
                ! $aperta => 'chiusa',
                array_filter($voci, fn ($v) => $v['spedito']) === [] => 'non_spedita',
                count(array_filter($voci, fn ($v) => $v['oggi'] && ! $v['spedito'])) === 0 => 'spedita',
                default => 'in_corso',
            },
            'entro' => $this->termini->perLaScheda($assemblea, $conteggi, $gestisce && $aperta),
            'convocati' => [
                'totale' => count($voci),
                'descrizione' => ConvocatiDellAssemblea::descrizione($voci),
                'fissati' => $uscite !== [],
                'righe' => $righe,
            ],
            // «Hai stampato le lettere il 26/11: ristampale»: dopo la stampa è cambiato l'avviso (la stampa non blocca niente).
            'lettere' => ['quante' => count($daStampare), 'attivo' => $percheLettere === null && $daStampare !== [], 'perche' => $percheLettere
                ?? ($daStampare === [] ? EmissioneDelleConvocazioni::NIENTE_DA_STAMPARE : null), 'ristampa' => $aperta ? ElencoDeiConvocati::fraseDellaRistampa($voci) : null],
            // Senza la casella, il perché porta alla pagina delle caselle; con l'ultima prova non andata, la conferma lo dice (decisione 572, proposta 11).
            // Con nessuno da convocare per PEC la casella non serve: la frase che manda a impostarla contraddirebbe la card del passo (M1, simulazione 21/11).
            'pec' => ['quante' => count($daMandare), 'attivo' => $perchePec === null && $daMandare !== [], 'perche' => $perchePecMostrato
                ?? ($perchePec === null && $daMandare === [] ? EmissioneDelleConvocazioni::NIENTE_DA_MANDARE : null),
                'dove' => $perchePecMostrato === PecDelloStudio::NON_IMPOSTATA ? $this->pec->dove() : null, 'avviso' => $this->pec->avviso()],
            // M3 (decisione 643): la spedizione fuori dai cinque giorni si dice, e non blocca. Quella già registrata (la più recente), e quella di oggi per ogni
            // canale della finestra «Registra la spedizione».
            'tardiva' => $aperta ? $this->laSpedizioneTardiva($assemblea, $voci) : null,
            'tardiva_oggi' => $aperta ? array_combine(['raccomandata', 'fax', 'a_mano'], array_map(
                fn (string $m) => $this->termini->spedizioneTardiva($assemblea, $m, \App\Helpers\DateHelper::oggiUtente())['frase'] ?? null, ['raccomandata', 'fax', 'a_mano'])) : null,
            'pec_non_comunicate' => $pecNonComunicate === 0 ? null : ($pecNonComunicate === 1 ? '1 PEC non risulta comunicata dalla persona'
                : $pecNonComunicate . ' PEC non risultano comunicate dalla persona') . ': segnalo nella sua scheda, o scegli la raccomandata',
            'videoconferenza' => self::consensoDellaVideoconferenza($assemblea),
            'venduti' => $this->nuovi->venduti($assemblea),
            'bacheca' => $this->bacheca($assemblea, $gestisce && $aperta),
            'prova' => ['attivo' => $uscite !== [], 'perche' => $uscite === [] ? 'La prova si stampa dopo il primo invio.' : null],
            // Ciò che spegne tutti e due i pulsanti (il luogo, la piattaforma): la riga dei perché lo scrive una volta, «Lettere e PEC», col link al passo delle date (C6, decisione 647).
            'comune' => $mancaAllAvviso !== null && $gestisce ? [
                'perche' => EmissioneDelleConvocazioni::cheCosaMancaInBreve($assemblea), 'lungo' => $mancaAllAvviso] : null,
            'foglio' => ['quante' => $aMano],
            'email_semplice' => 'L\'email semplice si può mandare come avviso in più, ma non vale come convocazione (art. 66 c. 3; Cass. 16399/2025).',
            'riconvoca' => [
                'si_puo' => $gestisce && $assemblea->stato === StatoAssemblea::CONVOCATA,
                'perche' => $assemblea->stato === StatoAssemblea::IN_PREPARAZIONE ? 'Finché non è spedita, le date si cambiano nel passo «Date e ordine del giorno».'
                    : (! $gestisce ? 'Serve «Gestisce le assemblee».' : null),
                'costo' => 'Riconvocare vuol dire rifare ' . (count($voci) === 1 ? 'l\'avviso' : 'i ' . count($voci) . ' avvisi') . ' con le date nuove: '
                    . 'gli invii di questa restano, segnati come superati, e i termini ripartono dalla nuova prima convocazione.',
            ],
            'gestisce' => $gestisce,
            'rotte' => self::rotte($assemblea),
        ];
    }

    /**
     * La spedizione registrata più recente che arriva dopo l'ultimo giorno utile, con la sua frase e il primo giorno valido; null se tutte sono in tempo.
     *
     * @param list<array<string, mixed>> $voci
     * @return ?array{frase: string, dal: string}
     */
    private function laSpedizioneTardiva(Assemblea $assemblea, array $voci): ?array
    {
        $trovata = null;
        foreach ($voci as $v) {
            foreach ($v['invii'] ?? [] as $invio) {
                $data = (string) ($invio->dati['spedizione']['data'] ?? '');
                $mezzo = (string) ($invio->dati['mezzo'] ?? '');
                if ($data === '' || $mezzo === '' || ($trovata !== null && $data <= $trovata['data'])) {
                    continue;
                }
                $t = $this->termini->spedizioneTardiva($assemblea, $mezzo, $data);
                if ($t !== null) {
                    $trovata = ['data' => $data, 'frase' => $t['frase'], 'dal' => $t['dal']];
                }
            }
        }

        return $trovata === null ? null : ['frase' => $trovata['frase'], 'dal' => $trovata['dal']];
    }

    /**
     * In videoconferenza (o mista) senza il giorno del consenso della maggioranza dei condòmini (art. 66 c. 6) la scheda lo dice: il merito sul consenso
     * diverge (scritto o anche tacito), quindi non blocca. Null in presenza o con il giorno scritto.
     */
    public static function consensoDellaVideoconferenza(Assemblea $assemblea): ?string
    {
        if (($assemblea->modalita ?? ModalitaAssemblea::PRESENZA) === ModalitaAssemblea::PRESENZA || $assemblea->consenso_videoconferenza_il !== null) {
            return null;
        }

        return 'Per la videoconferenza serve il consenso della maggioranza dei condòmini (art. 66 c. 6): nel passo «Date e ordine del giorno» non c\'è '
            . 'il giorno in cui è stato dato. Il programma non blocca: decidi tu.';
    }

    /** @param list<array<string, mixed>> $voci @return array{pec: int, raccomandata: int, a_mano: int, fax: int, senza: int} */
    public static function perMezzo(array $voci): array
    {
        $c = ['pec' => 0, 'raccomandata' => 0, 'a_mano' => 0, 'fax' => 0, 'senza' => 0];
        foreach ($voci as $v) {
            $m = $v['mezzo']['valido'] ? (string) $v['mezzo']['mezzo'] : 'senza';
            $c[$m] = ($c[$m] ?? 0) + 1;
        }

        return $c;
    }

    /** @return array<string, string> */
    public static function rotte(Assemblea $assemblea): array
    {
        $p = ['condominio' => $assemblea->condominio_id, 'assemblea' => $assemblea->id];
        $r = fn (string $nome, array $piu = []) => Route::has($nome) ? route($nome, $p + $piu) : '';

        return [
            'lettere' => $r('admin.gestionale.assemblee.convocazione.lettere'),
            'pec' => $r('admin.gestionale.assemblee.convocazione.pec'),
            'spedizione' => $r('admin.gestionale.assemblee.convocazione.spedizione'),
            'date' => Route::has('admin.gestionale.assemblee.show') ? route('admin.gestionale.assemblee.show', $p + ['scheda' => 'date']) : '',
            'prova' => $r('admin.gestionale.assemblee.convocazione.prova'),
            'foglio' => $r('admin.gestionale.assemblee.convocazione.foglio'),
            'foglio_firmato' => $r('admin.gestionale.assemblee.convocazione.foglio-firmato'),
            'bacheca' => $r('admin.gestionale.assemblee.convocazione.bacheca'),
            'raccomandate_entro' => $r('admin.gestionale.assemblee.convocazione.raccomandate-entro'),
            'riconvoca' => $r('admin.gestionale.assemblee.riconvoca'),
            'affissione' => $r('admin.gestionale.assemblee.update'),
            'consegna' => $r('admin.gestionale.assemblee.invii.consegna', ['invio' => '__INVIO__']),
            'prova_invio' => $r('admin.gestionale.assemblee.invii.prova', ['invio' => '__INVIO__']),
            'ricevuta' => $r('admin.gestionale.assemblee.invii.ricevuta', ['invio' => '__INVIO__']),
            'mezzo' => $r('admin.gestionale.assemblee.convocazione.mezzo'),
        ];
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    /** «entro oggi 10/12», «entro il 03/12»: il giorno di spedizione del mezzo, mai uno passato (decisione 665). */
    private function entroDaOggi(Assemblea $assemblea, ?string $mezzo): ?string
    {
        $giorno = $this->termini->entroPer($assemblea, $mezzo);

        return $giorno === null ? null : TerminiDellaConvocazione::entroDaOggi($giorno);
    }

    private function riga(Assemblea $assemblea, array $v, $perInvio, $fogli, ?string $ultimo, bool $siAgisce): array
    {
        /** @var ?RegistroInvio $invio */
        $invio = $v['invio'];
        $mezzo = $v['mezzo'];
        $mezzoDellInvio = $invio?->dati['mezzo'] ?? $mezzo['mezzo'];
        $g = fn (?string $giorno) => $giorno === null ? '' : Carbon::parse($giorno)->format('d/m');
        [$stato, $dettaglio] = match (true) {
            $invio === null && ! $mezzo['valido'] => ['non_convocabile', $mezzo['perche']],
            $invio === null && $mezzo['mezzo'] === 'a_mano' => ['da_consegnare', $ultimo !== null ? $this->entroDaOggi($assemblea, 'a_mano') : null],
            $invio === null => ['da_spedire', null],
            $invio->stato === RegistroInvio::STATO_NON_PARTITA => ['non_partita', \App\Services\Posta\ErroreDellaPec::frase($invio->errore, null)],
            // Il lavoro si è fermato durante il collegamento: non si sa se il gestore l'ha presa.
            $invio->stato === RegistroInvio::STATO_IN_CODA && $invio->esito === null => ['da_verificare',
                'guarda nella casella PEC se c\'è la ricevuta di accettazione; se non c\'è, manda di nuovo'],
            $invio->esito === RegistroInvio::ESITO_CONSEGNATA => ['consegnata', $this->dettaglioDellaConsegna($invio)],
            $invio->esito === RegistroInvio::ESITO_IN_GIACENZA => ['in_giacenza', 'avviso del ' . $g($invio->ricevuta_il?->format('Y-m-d')) . ': conta da qui'],
            $invio->esito === RegistroInvio::ESITO_RIFIUTATA => ['rifiutata', $g($invio->esito_il?->format('Y-m-d')) . ': non conta da sola, decide il presidente'],
            $invio->esito === RegistroInvio::ESITO_NON_CONSEGNATA => ['non_consegnata', $g($invio->esito_il?->format('Y-m-d')) . ': spedisci di nuovo o consegna a mano'],
            (bool) ($v['da_ristampare'] ?? false) => ['da_ristampare', 'stampata il ' . $invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m')
                . ': l\'avviso è cambiato'],
            // Una lettera stampata a chi non è più fra i convocati (il punto del conduttore tolto, il perimetro cambiato): non si spedisce.
            (bool) ($v['non_piu_convocato'] ?? false) => ['non_piu_convocato', 'stampata il ' . $invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m')
                . ': non spedirla'],
            // Il giorno scelto, mai uno passato: con la prima convocazione troppo vicina «entro oggi 10/12» (decisione 665).
            $mezzoDellInvio === 'a_mano' => ['da_consegnare', $ultimo !== null ? $this->entroDaOggi($assemblea, 'a_mano') : null],
            $invio->stato === RegistroInvio::STATO_STAMPATA => ['stampata', $this->entroDaOggi($assemblea, $mezzoDellInvio)],
            $invio->canale === RegistroInvio::CANALE_PEC => ['spedita', $invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m \a\l\l\e H:i')
                . ' · accettata dal gestore'],
            default => ['spedita', trim($g($invio->dati['spedizione']['data'] ?? null) . (filled($invio->dati['spedizione']['numero'] ?? null)
                ? ' · n. ' . $invio->dati['spedizione']['numero'] : ''))],
        };
        // Dopo un invio che non vale (non partito, non consegnato) la persona si spedisce di nuovo: senza mezzo valido lo dice.
        if (in_array($stato, ['non_partita', 'non_consegnata'], true) && ! $mezzo['valido']) {
            $dettaglio .= ' · ' . $mezzo['perche'];
        }
        $prova = null;
        if ($invio !== null) {
            $foglio = isset($invio->dati['foglio_documento_id']) ? $fogli->get((int) $invio->dati['foglio_documento_id']) : null;
            $doc = ConsegneDellaConvocazione::prova($invio, $perInvio->get((int) $invio->id) ?? collect(), $foglio);
            if ($doc !== null && Route::has('admin.gestionale.assemblee.invii.prove.scarica')) {
                $nome = match (true) {
                    $foglio !== null && (int) $doc->id === (int) $foglio->id => 'Foglio',
                    $invio->canale === RegistroInvio::CANALE_PEC => 'Ricevuta',
                    $invio->esito === RegistroInvio::ESITO_IN_GIACENZA => 'Avviso',
                    $mezzoDellInvio === 'raccomandata' => 'Cartolina',
                    default => 'Prova',
                };
                $prova = ['nome' => $nome, 'url' => route('admin.gestionale.assemblee.invii.prove.scarica', ['condominio' => $assemblea->condominio_id,
                    'assemblea' => $assemblea->id, 'invio' => (int) ($foglio !== null && (int) $doc->id === (int) $foglio->id ? $foglio->documentable_id : $invio->id),
                    'documento' => $doc->id])];
            }
        }
        [$parola, $colore] = self::STATI[$stato];
        $uscito = $invio !== null && $invio->stato !== RegistroInvio::STATO_NON_PARTITA;

        return [
            'chiave' => (string) $v['chiave'],
            'anagrafica_id' => $v['anagrafica_id'] === null ? null : (int) $v['anagrafica_id'],
            'nome' => (string) $v['nome'],
            'ruolo' => $v['ruolo'],
            'unita' => (string) $v['unita_parole'],
            'quota' => $v['quota'],
            'mezzo' => $mezzo['valido'] || $uscito ? $mezzoDellInvio : null,
            'mezzo_parola' => $mezzo['valido'] || $uscito ? MezzoDiConvocazione::parola($mezzoDellInvio) : 'Nessun mezzo',
            'mezzo_proposto' => ! $uscito && (bool) $mezzo['proposto'],
            'pec_non_comunicata' => ! $uscito && (bool) ($mezzo['pec_non_comunicata'] ?? false),
            'stato' => $stato,
            'stato_parola' => $parola,
            'colore' => $colore,
            'dettaglio' => $dettaglio === '' ? null : $dettaglio,
            'prova' => $prova,
            'invio_id' => $invio?->id,
            'pdf_url' => $invio !== null && Route::has('admin.gestionale.assemblee.invii.pdf') && isset($invio->dati['documento_id'])
                ? route('admin.gestionale.assemblee.invii.pdf', ['condominio' => $assemblea->condominio_id, 'assemblea' => $assemblea->id, 'invio' => $invio->id]) : null,
            'in_tempo' => $this->termini->inTempo($assemblea, $invio?->ricevuta_il?->format('Y-m-d')),
            // La lettera da ristampare non si spedisce e non si consegna: prima si ristampa.
            'azioni' => [
                'spedizione' => $siAgisce && $invio !== null && $invio->stato === RegistroInvio::STATO_STAMPATA && ! in_array($stato, ['da_ristampare', 'non_piu_convocato'], true),
                'consegna' => $siAgisce && $uscito && $invio->esito === null && ! in_array($stato, ['da_ristampare', 'non_piu_convocato'], true)
                    && ($invio->stato !== RegistroInvio::STATO_STAMPATA || $mezzoDellInvio === 'a_mano'),
                'correggi' => $siAgisce && $uscito && $invio->esito !== null,
                'prova' => $siAgisce && $uscito,
                // La raccomandata in giacenza ritirata dopo: un fatto nuovo, senza perché.
                'ritiro' => $siAgisce && $uscito && $invio->esito === RegistroInvio::ESITO_IN_GIACENZA,
                // La ricevuta PEC del gestore, trascinata sulla riga: anche su una PEC rimasta in coda.
                'ricevuta' => $siAgisce && $invio !== null && $invio->canale === RegistroInvio::CANALE_PEC && $invio->stato !== RegistroInvio::STATO_NON_PARTITA,
            ],
            // Che cosa dicono i pulsanti «Stampa le lettere» e «Manda le PEC» di questa riga: le spunte contano solo le righe del loro mezzo (decisione 647).
            'per' => [
                'lettere' => ElencoDeiConvocati::daStampareLaVoce($v),
                'pec' => (! $v['vale'] || $v['da_ristampare']) && $v['oggi'] && $v['mezzo']['valido'] && $v['mezzo']['mezzo'] === 'pec',
            ],
            // Il mezzo si sceglie nella riga, per questa assemblea, finché l'avviso non è spedito; la PEC solo se la persona ha una PEC.
            'scelta' => [
                'si_puo' => $siAgisce && $v['oggi'] && ! $v['spedito'],
                'ha_pec' => trim((string) ($v['pec'] ?? '')) !== '',
                'per_assemblea' => (bool) ($v['mezzo_per_assemblea'] ?? false),
                'della_scheda' => $v['mezzo_della_scheda'] ?? null,
            ],
            'esito' => $invio?->esito,
            'ricevuta_il' => $invio?->ricevuta_il?->format('Y-m-d'),
            'esito_il' => $invio?->esito_il?->format('Y-m-d H:i'),
        ];
    }

    private function dettaglioDellaConsegna(RegistroInvio $invio): string
    {
        $mezzo = $invio->dati['mezzo'] ?? null;
        $giorno = $invio->ricevuta_il?->format('d/m') ?? '';
        $ora = $invio->esito_il?->format('H:i');
        if ($mezzo === 'pec' && $ora !== null && $ora !== '00:00') {
            return $giorno . ' alle ' . $invio->esito_il->format('H:i');
        }
        if ($mezzo === 'a_mano') {
            return $giorno . (isset($invio->dati['foglio_documento_id']) ? ', firma' : '');
        }

        return $giorno;
    }

    /** @return array{acceso: bool, adesso: string, si_puo: bool, perche: ?string, url: ?string} */
    private function bacheca(Assemblea $assemblea, bool $siAgisce): array
    {
        $comunicazione = $assemblea->comunicazione_id === null ? null : $assemblea->comunicazione()->first();
        $url = $comunicazione !== null && Route::has('admin.comunicazioni.show') ? route('admin.comunicazioni.show', $comunicazione) : null;

        // «Senza dati personali» si dice solo se ogni punto ha la sua frase per la bacheca: il testo del punto non ci va mai, e al suo posto c'è la materia.
        $manca = AvvisoInBacheca::mancaLaFrase($assemblea);

        return [
            'acceso' => $comunicazione !== null,
            'adesso' => $comunicazione !== null
                ? ($comunicazione->is_published ? 'pubblicato il ' . $comunicazione->created_at?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m')
                    : 'scritto, non ancora pubblicato') . '. Dice che non vale come convocazione, e lo leggono anche gli inquilini.'
                    . ($manca === null ? ' Ogni punto ha la sua frase per la bacheca, senza dati personali.' : ' ' . $manca)
                : 'nessun avviso in bacheca. Si apre la comunicazione già scritta' . ($manca === null ? ' con le frasi dei punti senza dati personali; la pubblichi tu.'
                    : '; la pubblichi tu. ' . $manca),
            'si_puo' => $siAgisce && $comunicazione === null,
            'perche' => $comunicazione !== null ? null : (! $siAgisce ? 'Serve «Gestisce le assemblee», con l\'assemblea da convocare.' : null),
            'url' => $url,
        ];
    }
}
