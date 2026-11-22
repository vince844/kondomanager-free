<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\ConvocazioneSeduta;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\EventoTipo;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\User;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\RegistroModifiche\AccessoAlRegistro;
use App\Settings\AssembleeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * I termini della convocazione (§4.5 del progetto della tappa 2; legge §1 e §2; decisione 565).
 *
 * - **L'ultimo giorno di ricezione**: la prima convocazione meno cinque giorni di calendario (venti con un punto sulla destinazione d'uso, art. 1117-ter). Il
 *   giorno in cui arriva conta, quello dell'assemblea no (Cass. 18635/2021): per lunedì 14/12/2026, mercoledì 09/12/2026.
 * - **Entro quando spedire**: PEC, a mano e fax lo stesso giorno; la raccomandata quel giorno meno i giorni della posta dello studio (di serie 3), contati come
 *   i giorni lavorativi che il programma conosce già ({@see CalendarioFiscaleService}: dal lunedì al venerdì, senza le feste nazionali) **dal giorno dopo la
 *   spedizione**, e il giorno proposto è un giorno lavorativo: per il 09/12, con l'8 dicembre festa, giovedì 03/12 (la proposta prudente della decisione 565).
 * - **La data scelta dall'amministratore** (`assemblee.raccomandate_entro_il`): il programma la usa dappertutto (la riga del passo «Convocazione», il
 *   compito «Spedire la convocazione entro il …» e il suo promemoria per email), ricorda la sua proposta e rifà «Come conto» su quella data: se arriva
 *   tardi lo dice con parole sue. Non blocca: decide l'amministratore. Un giorno già passato non si sceglie.
 * - **Dentro i termini** una persona con il giorno di ricezione (`registro_invii.ricevuta_il`) non dopo l'ultimo giorno.
 * - **La prima convocazione troppo vicina** (decisione 665): quando il giorno di legge è già passato, «Spedisco le raccomandate entro» propone oggi, e con lui
 *   dicono oggi la riga di ogni persona, la strada dei sei passi, il compito dell'Action inbox e il suo promemoria ({@see entroPer()}, «entro oggi 21/11»);
 *   il compito non risulta scaduto e dice «fuori dai cinque giorni dell'art. 66» ({@see fuoriDaiCinqueGiorni()}). Con il tempo dei cinque giorni il giorno
 *   di legge resta com'è.
 */
class TerminiDellaConvocazione
{
    /** Un giorno già passato non si sceglie: la frase del server, e quella sotto una data salvata che è passata. */
    public const GIA_PASSATO = 'Il giorno è già passato: scegli oggi o un giorno dopo.';

    /** I giorni della settimana, dal lunedì (ISO 1). */
    private const GIORNI = [1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì', 4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato', 7 => 'domenica'];

    private const BREVI = [1 => 'lun', 2 => 'mar', 3 => 'mer', 4 => 'gio', 5 => 'ven', 6 => 'sab', 7 => 'dom'];

    private const NUMERI = [1 => 'un giorno', 2 => 'due giorni', 3 => 'tre giorni', 4 => 'quattro giorni', 5 => 'cinque giorni', 6 => 'sei giorni',
        7 => 'sette giorni', 8 => 'otto giorni', 9 => 'nove giorni', 10 => 'dieci giorni'];

    /** Le feste nazionali a data fissa, con il nome per «Come conto». */
    private const NOMI_DELLE_FESTE = ['01-01' => 'Capodanno', '01-06' => 'l\'Epifania', '04-25' => 'la Liberazione', '05-01' => 'la festa del lavoro',
        '06-02' => 'la festa della Repubblica', '08-15' => 'Ferragosto', '11-01' => 'Ognissanti', '12-08' => 'l\'Immacolata', '12-25' => 'Natale',
        '12-26' => 'Santo Stefano'];

    public function __construct(private readonly CalendarioFiscaleService $calendario) {}

    /** La prima seduta della prima convocazione, con l'ora come si legge. */
    public function primaConvocazione(Assemblea $assemblea): ?CarbonImmutable
    {
        $seduta = SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('convocazione', ConvocazioneSeduta::PRIMA->value)
            ->orderBy('numero')->first();

        return $seduta?->fissata_il === null ? null : CarbonImmutable::parse($seduta->fissata_il->format('Y-m-d H:i:s'));
    }

    /** Cinque giorni prima della prima convocazione; venti con un punto sulla destinazione d'uso (art. 1117-ter). */
    public function giorniPrima(Assemblea $assemblea): int
    {
        return ConvocatiDellAssemblea::conDestinazioneDUso($assemblea) ? 20 : 5;
    }

    /** L'ultimo giorno in cui l'avviso può arrivare. */
    public function ultimoGiorno(Assemblea $assemblea): ?CarbonImmutable
    {
        return $this->primaConvocazione($assemblea)?->startOfDay()->subDays($this->giorniPrima($assemblea));
    }

    public function giorniDiPosta(): int
    {
        return max(0, (int) app(AssembleeSettings::class)->giorni_di_posta);
    }

    /** Il giorno in cui una raccomandata spedita quel giorno arriva: l'N-esimo giorno lavorativo dopo la spedizione, il giorno della spedizione escluso. */
    public function arrivoAtteso(string|\DateTimeInterface $spedizione): CarbonImmutable
    {
        $giorno = CarbonImmutable::parse(is_string($spedizione) ? $spedizione : $spedizione->format('Y-m-d'))->startOfDay();
        $contati = 0;
        while ($contati < $this->giorniDiPosta()) {
            $giorno = $giorno->addDay();
            if ($this->calendario->isGiornoLavorativo($giorno)) {
                $contati++;
            }
        }

        return $giorno;
    }

    /** La proposta del programma: il giorno lavorativo più tardi da cui la raccomandata arriva entro l'ultimo giorno. */
    public function proposta(Assemblea $assemblea): ?CarbonImmutable
    {
        $ultimo = $this->ultimoGiorno($assemblea);
        if ($ultimo === null) {
            return null;
        }
        $giorno = $ultimo;
        for ($i = 0; $i < 60; $i++) {
            if ($this->calendario->isGiornoLavorativo($giorno) && $this->arrivoAtteso($giorno)->lte($ultimo)) {
                return $giorno;
            }
            $giorno = $giorno->subDay();
        }

        return $giorno;
    }

    /**
     * La proposta come la mostra «Spedisco le raccomandate entro» (decisione 662): mai un giorno passato. Con la prima convocazione troppo vicina il giorno
     * della proposta è già passato, e il programma propone oggi, con la frase dei cinque giorni. Dalla decisione 665 la seguono anche il compito dell'Action
     * inbox, i promemoria, la strada dei passi e la riga di ogni persona ({@see raccomandateEntro()}).
     */
    public function propostaDaOggi(Assemblea $assemblea): ?CarbonImmutable
    {
        $proposta = $this->proposta($assemblea);
        $oggi = DateHelper::oggiUtenteImmutable()->startOfDay();

        return $proposta !== null && $proposta->lt($oggi) ? $oggi : $proposta;
    }

    /**
     * I primi giorni della prima convocazione che hanno i cinque giorni dell'art. 66 c. 3, contando da oggi (decisione 662): con la PEC e la consegna a mano
     * l'avviso arriva oggi, con la raccomandata dopo i giorni di posta dello studio ({@see arrivoAtteso()}); il giorno di ricezione conta, quello
     * dell'assemblea no, e i giorni sono cinque (la destinazione d'uso, con i venti giorni, si dice quando c'è l'assemblea).
     *
     * @return array{oggi: string, dal_a_mano: string, dal_raccomandata: string}
     */
    public function primiGiorniDaOggi(): array
    {
        $oggi = DateHelper::oggiUtenteImmutable()->startOfDay();

        return ['oggi' => $oggi->format('Y-m-d'), 'dal_a_mano' => $oggi->addDays(5)->format('Y-m-d'),
            'dal_raccomandata' => $this->arrivoAtteso($oggi)->addDays(5)->format('Y-m-d')];
    }

    /**
     * La frase dei cinque giorni al passo 1 e nella convocazione (decisione 662): la prima convocazione di quel giorno non ha il tempo, con i due giorni da cui
     * lo avrebbe e il lavoro urgente (art. 1135 c. 2). Non blocca. Null se il giorno ha il tempo, anche per la raccomandata. La stessa frase è in
     * `fraseDeiCinqueGiorni()` di `resources/js/lib/assemblee/date.ts`.
     *
     * @param array{oggi: string, dal_a_mano: string, dal_raccomandata: string} $termini {@see primiGiorniDaOggi()}
     */
    public static function fraseDeiCinqueGiorni(string $prima, array $termini): ?string
    {
        $giorno = substr($prima, 0, 10);
        if ($giorno >= $termini['dal_raccomandata']) {
            return null;
        }
        $gm = fn (string $g) => CarbonImmutable::parse($g)->format('d/m');

        return 'Con la prima convocazione del ' . $gm($giorno) . ' non c\'è il tempo dei cinque giorni (art. 66 c. 3): per raccomandata può essere dal '
            . $gm($termini['dal_raccomandata']) . ', per PEC o a mano dal ' . $gm($termini['dal_a_mano']) . '. Si può convocare lo stesso, ma la delibera è '
            . 'annullabile da chi non è convocato in tempo. Per un lavoro urgente puoi agire subito e riferire alla prima assemblea (art. 1135 c. 2).';
    }

    /**
     * La data delle raccomandate che vale: quella scelta dall'amministratore, o la proposta come la mostra il campo, mai un giorno passato (decisione 665:
     * con la prima convocazione troppo vicina, oggi).
     */
    public function raccomandateEntro(Assemblea $assemblea): ?CarbonImmutable
    {
        return $assemblea->raccomandate_entro_il !== null ? CarbonImmutable::parse($assemblea->raccomandate_entro_il->format('Y-m-d'))
            : $this->propostaDaOggi($assemblea);
    }

    /**
     * Il giorno entro cui spedire con un mezzo: la raccomandata con la sua data, gli altri l'ultimo giorno; con l'ultimo giorno già passato (la prima
     * convocazione troppo vicina, decisione 665) oggi, come la proposta del campo.
     */
    public function entroPer(Assemblea $assemblea, ?string $mezzo): ?CarbonImmutable
    {
        if ($mezzo === 'raccomandata') {
            return $this->raccomandateEntro($assemblea);
        }
        $ultimo = $this->ultimoGiorno($assemblea);
        $oggi = DateHelper::oggiUtenteImmutable()->startOfDay();

        return $ultimo !== null && $ultimo->lt($oggi) ? $oggi : $ultimo;
    }

    /**
     * La spedizione di oggi è fuori dai cinque giorni dell'art. 66 (decisione 665): il giorno di legge del mezzo (la proposta per la raccomandata, l'ultimo
     * giorno per gli altri) è già passato. Il compito lo dice; non blocca.
     */
    public function fuoriDaiCinqueGiorni(Assemblea $assemblea, ?string $mezzo): bool
    {
        $legge = $mezzo === 'raccomandata' ? $this->proposta($assemblea) : $this->ultimoGiorno($assemblea);

        return $legge !== null && $legge->lt(DateHelper::oggiUtenteImmutable()->startOfDay());
    }

    /** «entro oggi 21/11» per il giorno di oggi, altrimenti «entro il 03/12», «entro l'11/11». */
    public static function entroDaOggi(CarbonImmutable $giorno): string
    {
        return $giorno->isSameDay(DateHelper::oggiUtenteImmutable()) ? 'entro oggi ' . $giorno->format('d/m') : ConsegneDellaConvocazione::entro($giorno);
    }

    /**
     * La spedizione di quel giorno con quel mezzo arriva dopo l'ultimo giorno utile (art. 66 disp. att.)? Allora la frase che lo dice con il primo giorno valido
     * per la prima convocazione (il giorno di ricezione conta, quello dell'assemblea no: arrivo più i giorni dei termini). La raccomandata arriva dopo i giorni
     * di posta dello studio, gli altri mezzi il giorno stesso. Non blocca: la scheda lo dice e si registra lo stesso. Null se è in tempo.
     *
     * @return ?array{frase: string, dal: string, ultimo: string, arrivo: string}
     */
    public function spedizioneTardiva(Assemblea $assemblea, ?string $mezzo, string $giorno): ?array
    {
        $ultimo = $this->ultimoGiorno($assemblea);
        $prima = $this->primaConvocazione($assemblea);
        if ($ultimo === null || $prima === null) {
            return null;
        }
        $spedizione = CarbonImmutable::parse($giorno)->startOfDay();
        $arrivo = in_array($mezzo, ['raccomandata'], true) ? $this->arrivoAtteso($spedizione) : $spedizione;
        if ($arrivo->lte($ultimo)) {
            return null;
        }
        $dal = $arrivo->addDays($this->giorniPrima($assemblea));
        $con = ['raccomandata' => 'la raccomandata', 'pec' => 'la PEC', 'a_mano' => 'la consegna a mano', 'fax' => 'il fax'][(string) $mezzo] ?? 'questo mezzo';

        return ['frase' => 'Spedita il ' . $spedizione->format('d/m') . ': con ' . $con . ' arriva dopo il ' . $ultimo->format('d/m') . ', l\'ultimo giorno utile per la prima '
            . 'convocazione del ' . $prima->format('d/m') . ' (art. 66 disp. att.). Per stare nei termini la prima convocazione può essere dal ' . $dal->format('d/m') . '.',
            'dal' => $dal->format('Y-m-d'), 'ultimo' => $ultimo->format('Y-m-d'), 'arrivo' => $arrivo->format('Y-m-d')];
    }

    /** Dentro i cinque giorni: il giorno di ricezione non dopo l'ultimo giorno. Null finché non c'è. */
    public function inTempo(Assemblea $assemblea, ?string $ricevutaIl): ?bool
    {
        $ultimo = $this->ultimoGiorno($assemblea);
        if ($ricevutaIl === null || $ultimo === null) {
            return null;
        }

        return substr($ricevutaIl, 0, 10) <= $ultimo->format('Y-m-d');
    }

    /**
     * «Spedisco le raccomandate entro»: la data scelta dall'amministratore, o vuota per tornare alla proposta. Una data uguale alla proposta si salva vuota (la
     * proposta segue le date dell'assemblea). Un giorno già passato non si sceglie (il calendario lo spegne, e qui si rifiuta con la frase); tornare alla
     * proposta si può sempre. Il compito «Spedire la convocazione entro il …» segue, e con lui il promemoria per email.
     */
    public function scegli(Assemblea $assemblea, ?string $giorno, User $utente): Assemblea
    {
        if (! in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true)) {
            throw ValidationException::withMessages(['giorno' => 'La data delle raccomandate si sceglie per un\'assemblea in preparazione o convocata.']);
        }
        if ($giorno !== null && $giorno < DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => self::GIA_PASSATO]);
        }
        $prima = $this->primaConvocazione($assemblea);
        if ($giorno !== null && $prima !== null && $giorno >= $prima->format('Y-m-d')) {
            throw ValidationException::withMessages(['giorno' => 'La data delle raccomandate va prima della prima convocazione, del ' . $prima->format('d/m/Y') . '.']);
        }
        if ($giorno !== null && $giorno === $this->propostaDaOggi($assemblea)?->format('Y-m-d')) {
            $giorno = null;
        }
        $assemblea->update(['raccomandate_entro_il' => $giorno, 'updated_by' => $utente->id]);
        app(CompitiDellaConvocazione::class)->sincronizza($assemblea->refresh());

        return $assemblea;
    }

    /**
     * «Entro quando spedire» del passo «Convocazione» (la bozza approvata del passo senza spazi vuoti): la proposta o la data scelta, «Come conto» sempre
     * accanto e fatto sulla data che vale, le righe d'aiuto sotto il campo (il compito e i giorni del promemoria per email), «Che cosa segue la data che
     * scegli», i giorni di posta dello studio con il collegamento all'impostazione per chi la può cambiare. La forma è `EntroQuandoSpedire` di
     * `components/assemblee/convocazione/tipi.ts`.
     *
     * Il compito e i giorni del promemoria si leggono dal compito aperto, che segue la data scelta: le righe dicono quello che c'è nell'Action inbox.
     *
     * @param array{raccomandata?: int, pec?: int, a_mano?: int, fax?: int} $quanti i convocati per mezzo
     * @return ?array<string, mixed>
     */
    public function perLaScheda(Assemblea $assemblea, array $quanti, bool $siCambia): ?array
    {
        $prima = $this->primaConvocazione($assemblea);
        $ultimo = $this->ultimoGiorno($assemblea);
        if ($prima === null || $ultimo === null) {
            return null;
        }
        $oggi = DateHelper::oggiUtenteImmutable();
        $proposta = $this->propostaDaOggi($assemblea);
        $sceltaDaTe = $assemblea->raccomandate_entro_il !== null;
        $entro = $sceltaDaTe ? $this->raccomandateEntro($assemblea) : $proposta;
        $giorniPrima = $this->giorniPrima($assemblea);
        $pec = (int) ($quanti['pec'] ?? 0);
        $aMano = (int) ($quanti['a_mano'] ?? 0) + (int) ($quanti['fax'] ?? 0);

        $frase = 'Per la prima convocazione di ' . $this->conIlGiorno($prima) . ' devono arrivare entro ' . $this->conIlGiorno($ultimo)
            . ': il giorno in cui arrivano conta, il ' . $prima->format('d/m') . ' no.';
        if ($giorniPrima === 20) {
            $frase = 'Con un punto sulla destinazione d\'uso devono arrivare venti giorni prima: per la prima convocazione di ' . $this->conIlGiorno($prima)
                . ' entro ' . $this->conIlGiorno($ultimo) . ' (art. 1117-ter).';
        }

        $altri = [];
        if ($pec > 0) {
            $altri[] = 'PEC (' . $pec . ')';
        }
        if ($aMano > 0) {
            $altri[] = 'consegne a mano (' . $aMano . ')';
        }
        $etichettaAltri = $altri === [] ? 'PEC e consegne a mano' : ucfirst(implode(' e ', $altri));

        // Il compito aperto e il suo giorno: le righe dicono quello che c'è nell'Action inbox, e i giorni del promemoria per email.
        $compito = app(CompitiDellaConvocazione::class)->aperti($assemblea, EventoTipo::SPEDIRE_CONVOCAZIONE)->first();
        $limite = $compito === null ? null : PromemoriaDellaConvocazione::limite($compito);
        $promemoria = $limite === null ? null : app(PromemoriaDellaConvocazione::class)->giorniDaDire($assemblea, $limite);

        $righe = [];
        $segue = [];
        if ($sceltaDaTe && $entro !== null) {
            $righe[] = 'Scelta da te' . ($assemblea->updated_at !== null ? ' il '
                . $assemblea->updated_at->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y') : '')
                . '; la proposta del programma resta ' . ($proposta !== null ? $this->conIlGiorno($proposta) : 'vuota') . '.';
            if ($compito !== null) {
                $segue[] = 'Il compito nell\'Action inbox: «' . $compito->title . '».';
            }
            if ($promemoria !== null) {
                $segue[] = 'Il promemoria per email: ' . match ($promemoria) {
                    'spento' => 'spento nelle impostazioni della posta.',
                    'senza_server' => 'non parte, il programma non ha un server di posta.',
                    default => $promemoria . '.',
                };
            }
            $segue[] = 'Le raccomandate stampate: nella tabella dei convocati, «' . ConsegneDellaConvocazione::entro($entro) . '».';
        } else {
            if ($siCambia) {
                $righe[] = 'Puoi scegliere un altro giorno, da oggi in poi: si salva quando lo cambi.';
            }
            if ($compito !== null) {
                $righe[] = 'Il compito «' . $compito->title . '» è nell\'Action inbox' . match ($promemoria) {
                    null => '',
                    'spento' => '; il promemoria per email è spento nelle impostazioni della posta',
                    'senza_server' => '; il promemoria per email non parte: il programma non ha un server di posta',
                    default => '; il promemoria per email arriva ' . $promemoria,
                } . '.';
            }
        }

        $posta = $this->giorniDiPosta();
        $utente = auth()->user();
        // Il giorno che vale è passato e le raccomandate sono ancora da spedire: il conto si fa su quando si può spedire davvero (oggi, o il primo giorno
        // lavorativo), mai sul giorno passato, che direbbe «Arriva in tempo» di una lettera che arriverà tardi.
        $passato = $entro !== null && $entro->lt($oggi) && $limite !== null && $limite->equalTo($entro);
        $spedizione = $passato ? ($this->calendario->isGiornoLavorativo($oggi) ? $oggi : $this->calendario->primoGiornoLavorativo($oggi)) : $entro;
        $propostaPassata = $proposta !== null && $proposta->lt($oggi);
        $conto = $spedizione === null ? null : $this->conto($spedizione, $ultimo, $prima);
        if ($conto !== null && $passato) {
            $conto['titolo'] = 'Come conto se spedisci ' . ($spedizione->equalTo($oggi) ? 'oggi, ' : '') . $this->conIlGiorno($spedizione);
        }

        return [
            'frase' => $frase,
            'frase_breve' => 'Devono arrivare entro ' . $this->conIlGiorno($ultimo) . '.',
            'ultimo_giorno' => $ultimo->format('Y-m-d'),
            'ultimo_giorno_parole' => $this->conIlGiorno($ultimo),
            // Il primo giorno che il campo lascia scegliere: oggi, a Roma.
            'oggi' => $oggi->format('Y-m-d'),
            'raccomandate' => $entro === null || $proposta === null ? null : [
                'quante' => (int) ($quanti['raccomandata'] ?? 0),
                'frase' => 'Spedisci le raccomandate entro ' . $this->conIlGiorno($entro),
                'entro' => $entro->format('Y-m-d'),
                'entro_parole' => $this->conIlGiorno($entro),
                'proposta' => $proposta->format('Y-m-d'),
                'proposta_parole' => $this->conIlGiorno($proposta),
                'scelta_da_te' => $sceltaDaTe,
                // Tornare a una proposta già passata porterebbe a un giorno passato.
                'torna' => $sceltaDaTe && ! $propostaPassata ? 'Torna alla proposta (' . $this->breveConIlMese($proposta) . ')' : null,
                // Il giorno salvato è passato e le raccomandate sono ancora da spedire: si mostra com'è, con la frase; la proposta passata dice di chi è.
                'passata' => ! $passato ? null : (! $sceltaDaTe ? 'La proposta del programma, ' . $this->conIlGiorno($proposta) . ', è già passata: scegli oggi '
                    . 'o un giorno dopo.' : self::GIA_PASSATO),
            ],
            // I cinque giorni non ci sono più: la stessa frase del passo 1, con «Sposta le date» nella scheda (decisioni 643 e 662). Con i venti giorni
            // della destinazione d'uso il passo 1 non la dice: lo dice già la nota sulla destinazione d'uso.
            'vicina' => $giorniPrima === 20 ? null : self::fraseDeiCinqueGiorni($prima->format('Y-m-d'), $this->primiGiorniDaOggi()),
            'altri' => ['etichetta' => $etichettaAltri, 'entro_parole' => $this->conIlGiorno($ultimo)],
            'conto' => $conto,
            'righe' => $righe,
            'segue' => $segue,
            // «Cambia» porta all'impostazione, per chi la può cambiare (l'amministratore).
            'giorni_di_posta' => ['adesso' => $posta === 1 ? '1 lavorativo' : $posta . ' lavorativi',
                'dove' => $utente instanceof User && AccessoAlRegistro::eAmministratore($utente) && Route::has('impostazioni.assemblee')
                    ? route('impostazioni.assemblee') : null],
            'si_cambia' => $siCambia,
            'destinazione_uso' => $giorniPrima === 20 ? $this->destinazioneDUso($assemblea, $ultimo) : null,
        ];
    }

    /**
     * «Come conto» accanto alla data: la settimana dalla spedizione all'arrivo, giorno per giorno, e se arriva in tempo. Si fa sulla data che vale (la
     * proposta, o quella scelta), con lo stesso calendario. In tempo dice il margine (all'ultimo giorno utile, il giorno in cui arriverebbe spedita il giorno
     * lavorativo dopo); tardi, il giorno in cui arriverebbe, e che la delibera si può impugnare. `sotto` nomina le feste che non contano, quando ce ne sono.
     *
     * @return array{titolo: string, frase: string, giorni: list<array{giorno: string, nota: string, tipo: string}>, sotto: ?string, esito: array{in_tempo: bool, titolo: string, frase: string}, piede: string}
     */
    public function conto(CarbonImmutable $spedizione, CarbonImmutable $ultimo, CarbonImmutable $prima): array
    {
        $posta = $this->giorniDiPosta();
        $giorni = [];
        $feste = [];
        $contati = 0;
        $giorno = $spedizione;
        $arrivo = $this->arrivoAtteso($spedizione);
        $giorni[] = ['giorno' => $this->breve($giorno), 'nota' => 'spedisci · non conta', 'tipo' => 'no'];
        while ($giorno->lt($arrivo)) {
            $giorno = $giorno->addDay();
            if (! $this->calendario->isGiornoLavorativo($giorno)) {
                $festa = $this->calendario->isFestivo($giorno) && ! $giorno->isWeekend();
                if ($festa) {
                    $feste[] = $giorno;
                }
                $giorni[] = ['giorno' => $this->breve($giorno), 'nota' => $festa ? 'festa · non conta' : 'non conta', 'tipo' => 'no'];
                continue;
            }
            $contati++;
            $ultimoDellaPosta = $contati === $posta;
            $giorni[] = ['giorno' => $this->breve($giorno), 'nota' => $ultimoDellaPosta
                ? $contati . '° · ' . ($giorno->lte($ultimo) ? 'arriva in tempo' : 'arriva tardi') : $contati . '° giorno di posta',
                'tipo' => $ultimoDellaPosta ? ($giorno->lte($ultimo) ? 'ar' : 'tardi') : 'po'];
        }
        $nomiFeste = array_map(fn (CarbonImmutable $g) => self::GIORNI[$g->dayOfWeekIso] . ' ' . $g->format('d/m')
            . (isset(self::NOMI_DELLE_FESTE[$g->format('m-d')]) ? ', ' . self::NOMI_DELLE_FESTE[$g->format('m-d')] : ''), $feste);
        $sotto = $nomiFeste === [] ? null : 'Non contano il giorno della spedizione, il sabato, la domenica e ' . implode(' e ', $nomiFeste)
            . '. Poi ' . $this->conIlGiorno($prima) . ' la prima convocazione.';

        return [
            'titolo' => 'Come conto ' . $this->conIlGiorno($spedizione),
            'frase' => 'La raccomandata deve arrivare entro ' . $this->conIlGiorno($ultimo) . '. Lo studio conta ' . (self::NUMERI[$posta] ?? $posta . ' giorni')
                . ' di posta, dal lunedì al venerdì, dal giorno dopo la spedizione.',
            'giorni' => $giorni,
            'sotto' => $sotto,
            'esito' => $this->esito($spedizione, $arrivo, $ultimo, $prima),
            'piede' => 'Conto prudente: spedire un giorno prima non costa niente.',
        ];
    }

    /**
     * Se la raccomandata spedita quel giorno arriva in tempo, e con quanto margine; o quando arriverebbe. Il titolo va in grassetto, la frase lo segue.
     *
     * @return array{in_tempo: bool, titolo: string, frase: string}
     */
    private function esito(CarbonImmutable $spedizione, CarbonImmutable $arrivo, CarbonImmutable $ultimo, CarbonImmutable $prima): array
    {
        $giorni = fn (int $n) => $n === 1 ? 'un giorno' : $n . ' giorni';
        if ($arrivo->lte($ultimo)) {
            $margine = (int) round($arrivo->diffInDays($ultimo));
            if ($margine > 0) {
                return ['in_tempo' => true, 'titolo' => 'Arriva in tempo', 'frase' => ', con ' . $giorni($margine) . ' di margine sul ' . $ultimo->format('d/m') . '.'];
            }
            $dopo = $this->calendario->primoGiornoLavorativo($spedizione->addDay());
            $arrivaDopo = $this->arrivoAtteso($dopo);

            return ['in_tempo' => true, 'titolo' => 'Arriva in tempo', 'frase' => ', l\'ultimo giorno utile. Spedita ' . $this->conIlGiorno($dopo) . ' arriverebbe '
                . $this->conIlGiorno($arrivaDopo) . ': ' . $giorni((int) round($ultimo->diffInDays($arrivaDopo))) . ' dopo.'];
        }
        $quando = $arrivo->gte($prima->startOfDay()) ? 'dopo la prima convocazione di ' . $this->conIlGiorno($prima)
            : $giorni((int) round($ultimo->diffInDays($arrivo))) . ' dopo il termine del ' . $ultimo->format('d/m');
        $venti = (int) round($ultimo->diffInDays($prima->startOfDay())) === 20;

        return ['in_tempo' => false, 'titolo' => 'Arriva tardi', 'frase' => ': spedita ' . $this->conIlGiorno($spedizione) . ' arriverebbe ' . $this->conIlGiorno($arrivo)
            . ', ' . $quando . '. Per chi la riceve dopo il ' . $ultimo->format('d/m') . ' i ' . ($venti ? 'venti' : 'cinque') . ' giorni non ci sono, e la delibera si '
            . 'può impugnare. Se tieni il ' . $spedizione->format('d/m') . ', decidi tu.'];
    }

    /** I giorni dell'affissione, dal primo all'ultimo compresi, che cadono prima del giorno della prima convocazione. */
    public static function giorniDiAffissionePrima(string $dal, string $al, CarbonImmutable $prima): int
    {
        $inizio = CarbonImmutable::parse($dal)->startOfDay();
        $fine = CarbonImmutable::parse($al)->startOfDay();
        $ultimoUtile = $prima->startOfDay()->subDay();
        if ($fine->gt($ultimoUtile)) {
            $fine = $ultimoUtile;
        }

        return $fine->lt($inizio) ? 0 : (int) round($inizio->diffInDays($fine)) + 1;
    }

    /** «giovedì 03/12». */
    public function conIlGiorno(CarbonImmutable $giorno): string
    {
        return self::GIORNI[$giorno->dayOfWeekIso] . ' ' . $giorno->format('d/m');
    }

    /** «gio 03». */
    private function breve(CarbonImmutable $giorno): string
    {
        return self::BREVI[$giorno->dayOfWeekIso] . ' ' . $giorno->format('d');
    }

    /** «gio 03/12». */
    private function breveConIlMese(CarbonImmutable $giorno): string
    {
        return self::BREVI[$giorno->dayOfWeekIso] . ' ' . $giorno->format('d/m');
    }

    /**
     * Con un punto sulla destinazione d'uso (art. 1117-ter): l'affissione per almeno trenta giorni consecutivi e i due contenuti obbligatori del punto.
     *
     * @return array{frase: string, mancano: list<string>, affissione: array{dal: ?string, al: ?string, si_cambia: bool}}
     */
    private function destinazioneDUso(Assemblea $assemblea, CarbonImmutable $ultimo): array
    {
        $mancano = [];
        $prima = $this->primaConvocazione($assemblea);
        if ($assemblea->affissione_dal === null || $assemblea->affissione_al === null) {
            $mancano[] = 'le date dell\'affissione (almeno trenta giorni consecutivi)';
        } elseif ($prima !== null && ($giorni = self::giorniDiAffissionePrima($assemblea->affissione_dal->format('Y-m-d'), $assemblea->affissione_al->format('Y-m-d'), $prima)) < 30) {
            // Si contano i giorni prima della prima convocazione: un avviso affisso dopo l'assemblea non avvisa nessuno.
            $mancano[] = 'un\'affissione di almeno trenta giorni consecutivi prima della prima convocazione del ' . $prima->format('d/m') . ': prima di quel giorno sono '
                . $giorni;
        }
        foreach ($assemblea->punti()->get() as $punto) {
            if ($punto->materia?->value !== 'destinazione_uso') {
                continue;
            }
            $dati = (array) ($punto->dati ?? []);
            if (trim((string) ($dati['parti_comuni'] ?? '')) === '') {
                $mancano[] = 'nel punto ' . $punto->numero . ' le parti comuni che cambiano';
            }
            if (trim((string) ($dati['nuova_destinazione'] ?? '')) === '') {
                $mancano[] = 'nel punto ' . $punto->numero . ' la nuova destinazione d\'uso';
            }
        }

        return [
            'frase' => 'C\'è un punto sulla destinazione d\'uso delle parti comuni: l\'avviso arriva per raccomandata o PEC ' . ConsegneDellaConvocazione::entro($ultimo)
                . ', venti giorni prima, ed è affisso per almeno trenta giorni consecutivi (art. 1117-ter).',
            'mancano' => $mancano,
            // Le due date dell'affissione, che la scheda scrive finché l'assemblea è in preparazione.
            'affissione' => ['dal' => $assemblea->affissione_dal?->format('Y-m-d'), 'al' => $assemblea->affissione_al?->format('Y-m-d'),
                'si_cambia' => $assemblea->stato === StatoAssemblea::IN_PREPARAZIONE],
        ];
    }
}
