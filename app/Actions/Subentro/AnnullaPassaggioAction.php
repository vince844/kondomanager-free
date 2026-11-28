<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Actions\Subentro;

use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Enums\EventoTipo;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\Gestionale\EventiRataCondomino;
use App\Services\Gestionale\InboxService;
use App\Services\Subentro\AccessoriAgliEredi;
use App\Services\Subentro\FermataDopoScrivi;
use App\Services\Subentro\FrasiObbligati;
use App\Services\Subentro\GuardieTitolarita;
use App\Services\Subentro\NudiDellEstinzione;
use App\Services\Subentro\QuoteNateDopoIlPassaggio;
use App\Services\Subentro\RegolaDelPiano;
use App\Services\Subentro\VociDaSpostare;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Annulla un passaggio registrato (1.11.0-beta.37, decisione 27 in `docs/subentro_e_competenza_temporale.md`).
 *
 * Fino alla beta.36 un passaggio non si annullava (decisione 24). Da qui si annulla **l'ultimo passaggio dell'unità** — l'ultimo
 * registrato, non l'ultimo per data, dalla 1.11.0-beta.50 (decisione 83.1) —
 * (e di ciascuna pertinenza passata con lei), **a rate intatte**, rileggendo al contrario il **registro** che il passaggio
 * ha scritto (`RegistraSubentroAction`, punto 8): le righe di titolarità che ha scritto tornano come prima, le bozze passate a chi è
 * entrato tornano a chi è uscito con le stesse regole congelate (e la quota del pregresso, divisa, torna una), il
 * conguaglio si toglie, i promemoria delle rate tornano a chi è uscito, il promemoria in agenda sparisce. Il passaggio
 * **resta** nella tabella, annullato, con la data, chi l'ha annullato e perché; il modello lo nasconde a ogni conto.
 *
 * Una regola sola per lo storico e per il server: `motivoBlocco()` dice perché no, e l'azione la richiama dentro la
 * transazione, **dopo** aver bloccato le righe che legge (vedi `execute()`). Si ferma, dicendo la via, quando:
 *
 * - il passaggio è di prima della beta.37 (nessun registro): ricostruire le righe dalle date sarebbe tirare a indovinare;
 * - dopo di lui, sull'unità o su una sua pertinenza, è registrato un altro passaggio, anche con una data precedente: si annulla
 *   prima quello;
 * - nella successione, un erede ha già versato sul debito ereditato e il suo incasso non è stornato (decisione 690 (3)): si storna prima l'incasso;
 * - il conguaglio è già assorbito da un piano (la stessa frase di `AnnullaConguaglioAction`);
 * - una quota toccata dopo il passaggio — una bozza passata a chi è entrato, o una quota nata dopo che l'annullamento
 *   cambierebbe (un piano generato o ricalcolato dopo, con la titolarità di dopo: di chi è entrato, di chi è uscito, o di
 *   chi ha preso i giorni rimasti scoperti) — è stata emessa, pagata, o ha un pagamento segnalato dal portale non ancora
 *   verificato (lettura stretta di «rate intatte», dichiarata nel verbale della beta);
 * - una riga di titolarità scritta dal passaggio è stata corretta a mano dopo: rimetterla «come prima» perderebbe la
 *   correzione;
 * - lo stato che l'annullamento lascerebbe non passa le guardie di «Associa» (una riga associata o ritoccata a mano dopo
 *   il passaggio, fuori dal registro, si sovrappone alla riga che si riapre o porta le quote oltre 100).
 *
 * Un piano generato o ricalcolato dopo il passaggio e non ancora emesso non ferma l'annullamento, ma va ricalcolato di nuovo: lo
 * dicono gli `avvisi()`, come l'anteprima del passaggio dice i piani da ricalcolare. Dalla 1.11.0-beta.51 lo dice anche il piano:
 * annullato il passaggio, `PianoRate::passaggiDaSeguire` lo conta fra quelli da seguire (con la chiave `annullamento.quota_max_id`
 * che l'annullamento scrive nel registro) e l'emissione, l'incasso e il rimborso su quel piano si fermano finché non si ricalcola:
 * emesse così, le quote di chi era entrato andrebbero a giornale per un passaggio che non c'è più. Un piano ricalcolato o eliminato
 * che si è portato via le bozze del registro non ferma niente nemmeno lui: non c'è più niente da rimettere.
 */
final class AnnullaPassaggioAction
{
    public function __construct(private readonly EventiRataCondomino $eventi) {}

    /**
     * Perché questo passaggio non si può annullare, o `null`. Senza scrivere niente: la legge anche lo storico.
     */
    /**
     * Rilievo L10 della Fase 1-bis della .44: che cosa un piano ha già assorbito — il conguaglio, o anche l'arretrato del defunto di una
     * successione —, con le sue parole.
     */
    private static function aperturaAssorbite(Subentro $padre, \Illuminate\Support\Collection $assorbite): string
    {
        $dellArretrato = $assorbite->contains(fn (Saldo $x) => in_array((int) $x->id, $padre->saldiDellArretrato(), true));

        return $dellArretrato
            ? 'Le righe dell\'arretrato del defunto di questo passaggio sono già state assorbite da un piano rate, e il passaggio non si annulla così com\'è.'
            : 'Il conguaglio di questo passaggio è già stato assorbito da un piano rate, e il passaggio non si annulla così com\'è.';
    }

    /** Il rifiuto con un saldo del Wallet legato al passaggio già assorbito da un piano: la stessa frase della coppia, con le stesse strade. */
    private static function fraseLegateAssorbite(Collection $legate, array $presiDaSeguire): string
    {
        return AnnullaConguaglioAction::fraseAssorbite($legate, 'Un saldo del Wallet legato a questo passaggio è già stato assorbito da un piano rate, e il passaggio non si annulla così com\'è.',
            'torna qui', perIlPassaggio: true, presiDaSeguire: $presiDaSeguire, legate: true);
    }

    /**
     * Perché questo passaggio non si può annullare, o `null`. Senza scrivere niente: la legge anche lo storico.
     *
     * Decisione 705 (1.12.0-beta.14): un passaggio registrato insieme ad altri (più persone che escono con lo stesso atto) si annulla con tutti
     * quelli dell'insieme, e il motivo è quello dell'insieme ({@see motivoDellInsieme()}), lo stesso che troverebbe l'azione.
     */
    public function motivoBlocco(Subentro $passaggio): ?string
    {
        $padre = $passaggio->subentro_padre_id ? Subentro::conAnnullati()->find($passaggio->subentro_padre_id) : $passaggio;
        if ($padre === null || $padre->annullato()) {
            return 'Questo passaggio è già stato annullato.';
        }
        $membri = $this->membriDaAnnullare($padre);

        return $membri->count() > 1 ? $this->motivoDellInsieme($membri) : $this->motivoDelPassaggio($padre);
    }

    /**
     * Decisione 705: i passaggi dell'insieme di questo, non annullati, nell'ordine in cui sono stati scritti; per un passaggio da solo, lui solo.
     *
     * @return Collection<int, Subentro>
     */
    private function membriDaAnnullare(Subentro $padre): Collection
    {
        return $padre->insieme_id === null ? collect([$padre]) : collect($padre->membriDellInsieme()->all());
    }

    /**
     * Decisione 705: il motivo dell'insieme, letto come lo troverebbe l'azione, che annulla i passaggi dall'ultimo registrato al primo. Prima un
     * passaggio registrato dopo l'insieme, su una delle sue unità, con la frase di oggi (è dell'insieme, non di una persona); poi ogni passaggio,
     * dall'ultimo, sullo stato che lascerebbero quelli dopo di lui già annullati ({@see motivoDelPassaggio()} con `$dopo`), con il nome della persona
     * davanti: «La parte di Marta Acciaioli: …».
     *
     * @param Collection<int, Subentro> $membri
     */
    private function motivoDellInsieme(Collection $membri): ?string
    {
        $famiglie = $membri->flatMap(fn (Subentro $m) => $this->famiglia($m))->values();
        if ($motivo = $this->passaggioDopo($famiglie, $famiglie->pluck('id')->map(fn ($id) => (int) $id)->all())) {
            return $motivo;
        }
        $propri = $this->nomiPropri($membri);
        foreach ($membri->sortByDesc('id')->values() as $membro) {
            $dopo = $membri->filter(fn (Subentro $m) => (int) $m->id > (int) $membro->id)->values();
            if ($motivo = $this->motivoDelPassaggio($membro, $dopo)) {
                return self::fraseDellaParte((string) ($this->nome($membro, 'uscente') ?? 'chi esce'), $motivo, $propri);
            }
        }

        return null;
    }

    /**
     * Il motivo di un passaggio solo, o di un passaggio dell'insieme letto come se quelli registrati dopo di lui nello stesso insieme (`$dopo`) fossero
     * già annullati: quelli non contano come «un altro passaggio registrato dopo», e le righe che hanno scritto o cambiato (la riga di chi entra,
     * sommata) si leggono come le lascerebbe il loro annullamento ({@see righeDopoGliAltri()}).
     *
     * @param Collection<int, Subentro>|null $dopo
     */
    private function motivoDelPassaggio(Subentro $padre, ?Collection $dopo = null): ?string
    {
        $dopo ??= collect();
        $famiglia = $this->famiglia($padre);

        if ($famiglia->contains(fn (Subentro $s) => ! is_array($s->registro))) {
            return 'Questo passaggio è stato registrato da una versione che non teneva il registro di ciò che '
                . 'scriveva: non si annulla dal programma. Si corregge a mano, da «Modifica associazione» e «Associa soggetto», come prima.';
        }

        $salta = $dopo->flatMap(fn (Subentro $m) => $this->famiglia($m)->pluck('id'))->map(fn ($id) => (int) $id)->values()->all();
        if ($motivo = $this->passaggioDopo($famiglia, $salta)) {
            return $motivo;
        }

        // Decisione 690 (3), Coda 708: un erede ha già versato sul debito ereditato con questa successione. Prima dei piani che hanno assorbito le righe
        // dell'arretrato o quelle legate: un piano che ha preso la coppia del versamento ha preso con lei anche l'arretrato, e la frase del piano direbbe
        // di eliminarlo senza dire dell'incasso; la strada è lo storno, che scrive la coppia rovescia se il piano l'ha già presa.
        if ($motivo = self::versamentiDegliErediRegistrati($padre)) {
            return $motivo;
        }

        $assorbite = Saldo::whereIn('subentro_id', $famiglia->pluck('id'))->where('is_applicato', true)->with('pianoRate')->get();
        if ($assorbite->isNotEmpty()) {
            return AnnullaConguaglioAction::fraseAssorbite($assorbite, self::aperturaAssorbite($padre, $assorbite),
                'torna qui', perIlPassaggio: true, presiDaSeguire: $this->presiDaSeguire($famiglia), conArretrato: $padre->arretratoAgliEredi());
        }

        // 1.11.0-beta.53 (Coda 244): un saldo del Wallet legato a questo passaggio, già assorbito da un piano, ferma l'annullamento come la
        // coppia, con le stesse strade d'uscita.
        $legate = Saldo::where('passaggio_id', $padre->id)->where('is_applicato', true)->with('pianoRate')->get();
        if ($legate->isNotEmpty()) {
            return self::fraseLegateAssorbite($legate, $this->presiDaSeguire($famiglia));
        }

        return $this->motivoDelleQuoteEDelleRighe($padre, $famiglia, $dopo);
    }

    /**
     * Le ultime regole di `motivoBlocco`, dopo i passaggi registrati dopo e i piani: le quote toccate dopo il passaggio, le righe corrette a mano. Con
     * `$dopo` (decisione 705) le righe si leggono come le lascerebbe l'annullamento dei passaggi dell'insieme registrati dopo.
     *
     * @param Collection<int, Subentro> $dopo
     */
    private function motivoDelleQuoteEDelleRighe(Subentro $padre, Collection $famiglia, Collection $dopo): ?string
    {
        // Decisione 493 (7): un erede con interessi sul debito ereditato registrati con questa successione: prima si annulla l'addebito. Prima della regola
        // delle quote nate dopo il passaggio, che fermerebbe con la frase delle rate emesse («annulla l'emissione»), che per un addebito non è la strada.
        if ($motivo = self::interessiEreditatiRegistrati($padre)) {
            return $motivo;
        }
        if ($motivo = $this->quoteNonIntatte($padre, $famiglia)) {
            return $motivo;
        }
        if ($motivo = AccessoriAgliEredi::motivo((array) ($padre->registro['arretrato']['accessori'] ?? []))) {
            return $motivo;
        }

        return $this->righeCorrette($famiglia, $dopo) ?? $this->righeInConflitto($famiglia, $dopo);
    }

    /**
     * Ciò che l'annullamento lascia da fare: i piani generati o ricalcolati dopo il passaggio, non ancora emessi, con
     * quote che l'annullamento cambierebbe (`quoteNateDopo`).
     *
     * Decisione 705: per un passaggio dell'insieme, quelli di tutti i passaggi dell'insieme, senza doppioni.
     *
     * @return list<string>
     */
    public function avvisi(Subentro $padre): array
    {
        $membri = $padre->annullato() ? collect([$padre]) : $this->membriDaAnnullare($padre);
        if ($membri->count() < 2) {
            return $this->avvisiDelPassaggio($padre);
        }

        return array_values(array_unique(array_merge(...$membri->sortByDesc('id')->map(fn (Subentro $m) => $this->avvisiDelPassaggio($m))->values()->all())));
    }

    /** Gli avvisi di un passaggio solo, o di uno dell'insieme (l'azione li legge così, uno per volta). @return list<string> */
    private function avvisiDelPassaggio(Subentro $padre): array
    {
        $famiglia = $this->famiglia($padre);

        $avvisi = $this->quoteNateDopo($padre, $famiglia)
            ->filter(fn ($q) => $q->scrittura_contabile_id === null)
            ->pluck('piano_nome')->unique()->values()
            ->map(fn (string $piano) => sprintf('Il piano «%s» è stato generato o ricalcolato dopo il passaggio: ricalcolalo di nuovo, così quote e riparto tornano sulla titolarità di prima.', $piano))
            ->all();

        // 1.11.0-beta.53: i saldi scritti a mano dal Wallet dopo la registrazione, senza legarli a un passaggio (la strada a mano di
        // prima del legame, o un saldo qualunque), per ogni passaggio: si nominano, non si toccano. I saldi legati a questo passaggio li dice
        // l'effetto (si tolgono); la fermata dopo «Scrivi» e quella del coinquilino non hanno più un avviso loro: senza saldi non c'è niente da dire.
        array_push($avvisi, ...FermataDopoScrivi::avvisiDeiSaldiAMano($padre));

        // Coda 254 (1.11.0-beta.51): la fine di un conduttore che ha fatto risalire la parte di una fermata di prima; annullata, la parte torna
        // com'era, e un saldo o un accordo adeguato nel frattempo si rivede. Se il conguaglio della fine di prima è già annullato non c'è più niente.
        foreach ($padre->registro['fermata_risale'] ?? [] as $f) {
            if (! FermataDopoScrivi::fineSenzaConguaglio($f)) {
                $avvisi[] = FermataDopoScrivi::avvisoRisaleAnnullando($f);
            }
        }

        // Coda 245 (decisione 95.6 b): il passaggio con la regola del piano che ha lasciato fuori dal conguaglio una parte (il resto): annullato,
        // quella parte non c'è più, e i saldi del Wallet legati al passaggio si tolgono con lui. Anche dopo «Annulla il conguaglio», della fine di
        // prima o di questo passaggio: il resto è la differenza del piano del passaggio (decisione 111.3), e fin lì valeva.
        foreach ($padre->registro['resto_del_piano'] ?? [] as $r) {
            if (! empty($r['wallet'])) {
                $avvisi[] = RegolaDelPiano::avvisoDelRestoAnnullando($r, Saldo::where('passaggio_id', (int) $padre->id)->where('immobile_id', (int) $r['immobile_id'])->exists());
            }
        }

        // Decisione 79 (2) e 86.1: l'inizio di una locazione che ha ridotto o azzerato una fermata di prima; annullato, la parte da regolare torna com'era.
        foreach ($padre->registro['fermata_ridotta'] ?? [] as $f) {
            if (! FermataDopoScrivi::fineSenzaConguaglio($f)) {
                $avvisi[] = FermataDopoScrivi::avvisoRidottaAnnullando($f);
            }
        }

        // D3 della prova della Coda 663: gli interessi del defunto registrati con la scelta «si fermano al decesso» restano come sono, e quelli passati agli
        // eredi tornano a suo nome.
        array_push($avvisi, ...self::avvisiDegliInteressi($padre));

        // Decisione 31.7 (1.11.0-beta.41): le voci che il passaggio ha spostato all'«Usufruttuario» restano dove sono. Valgono
        // per tutta la tabella e l'amministratore può averle toccate dopo: si nominano, non si disfano.
        $voci = $padre->registro['voci_spostate'] ?? [];
        if ($voci !== []) {
            $uno = count($voci) === 1;
            $avvisi[] = sprintf($uno
                ? 'La voce che questo passaggio ha spostato dal «Proprietario» all\'«Usufruttuario» resta com\'è: %s. Vale per tutta la tabella, anche per le altre unità in usufrutto; se va riportata com\'era, si cambia dalla pagina della voce.'
                : 'Le voci che questo passaggio ha spostato dal «Proprietario» all\'«Usufruttuario» restano come sono: %s. Valgono per tutta la tabella, anche per le altre unità in usufrutto; se vanno riportate com\'erano, si cambiano dalla pagina della voce.',
                // Rilievo A6 della Fase 1-bis: i coefficienti di prima, dal registro. «Al Proprietario» era falso per una voce divisa.
                $this->elenco(array_map(fn (array $v) => VociDaSpostare::etichetta($v, sprintf('; prima %s', VociDaSpostare::coefficientiAParole($v['prima'] ?? []))), $voci)));
        }

        // Decisione 46 (rilievo W2): con la rinuncia o con il conguaglio annullato le parti hanno regolato fra loro. Annullato e
        // registrato di nuovo, il passaggio non ha più niente da regolare, e il piano ricalcolato addebita a chi entra i suoi giorni.
        if ($padre->conguaglioRinunciato() || $padre->conguaglioAnnullato()) {
            $cifra = $padre->regolatoFuoriInParole();
            if ($cifra !== null || ! is_array($padre->registro['regolato_fuori'] ?? null)) {
                // Decisione 72 (1.11.0-beta.48): il conguaglio non scritto della successione non è un accordo fra le parti. Dalla .50 anche
                // quello della locazione (decisione 77), e la frase dice di quale passaggio è.
                $avvisi[] = $padre->conguaglioNonScritto() && ! $padre->conguaglioAnnullato()
                    ? sprintf('Il conguaglio %s non è stato scritto (%s): se il passaggio si registra di nuovo e il piano si ricalcola, il condominio addebita %s.',
                        $padre->conguaglioInParole(), $cifra ?? 'le cifre non sono nel registro',
                        // Coda 245 (1.11.0-beta.55): con la regola del piano, a ciascun conduttore la sua parte, per quota e per giorni.
                        ($padre->registro['conguaglio']['piano'] ?? []) !== [] ? $padre->aChiVannoIGiorni() : 'a ciascuno i suoi giorni')
                    : sprintf('Le parti hanno già regolato fra loro %s: se il passaggio si registra di nuovo e il piano si ricalcola, il condominio addebita %s, e quell\'accordo va rifatto fra le parti.',
                        $cifra ?? 'il conguaglio di questo passaggio', $padre->aChiVannoIGiorni());
            }
        }

        return $avvisi;
    }

    /**
     * Che cosa l'annullamento rimette, detto prima di confermare: solo ciò che questo passaggio ha davvero toccato (una
     * locazione non sposta rate, una rinuncia non scrive conguaglio, un piano ricalcolato si è portato via le bozze).
     *
     * Decisione 705: per un passaggio dell'insieme, gli effetti di tutti ({@see frasiEffettiDellInsieme()}).
     *
     * @return list<string>
     */
    public function effetti(Subentro $padre): array
    {
        $membri = $padre->annullato() ? collect([$padre]) : $this->membriDaAnnullare($padre);
        if ($membri->count() > 1) {
            $frasi = self::frasiEffettiDellInsieme($membri->map(fn (Subentro $m) => [$this->contiDegliEffetti($m), $this->nome($m, 'uscente'), $this->nome($m, 'entrante')])->values()->all(), false);
            $chiTorna = $this->chiTornaTitolare($membri);

            return $chiTorna === [] ? $frasi : array_values(array_merge(...array_map(
                fn (string $f) => $f === 'Le righe di titolarità scritte dai passaggi tornano come prima.' ? $chiTorna : [$f], $frasi)));
        }

        return self::frasiEffetti($this->contiDegliEffetti($padre), $this->nome($padre, 'uscente'), $this->nome($padre, 'entrante'), false, $padre->successione());
    }

    /**
     * Decisione 705, la finestra «Annulla i passaggi» della bozza approvata della tappa 7: al posto di «Le righe di titolarità scritte dai passaggi tornano
     * come prima.», chi torna titolare con la sua quota e che cosa succede a chi era entrato — «Cecilia Angeli e Marta Acciaioli tornano titolari al 50 %
     * senza data di fine; Luca Toscani esce dall'unità.»; il terzo fratello che aveva già la sua parte «torna al 33,33 %». Letto dalle righe dell'unità come
     * le lascerebbe l'annullamento di tutti ({@see righeDopoGliAltri()}), senza scrivere niente. Sulle pertinenze la frase di oggi, con il loro nome. Vuoto
     * se una riga di chi esce non si trova più: allora resta la frase di oggi.
     *
     * @param Collection<int, Subentro> $membri
     * @return list<string>
     */
    private function chiTornaTitolare(Collection $membri): array
    {
        $primo = $membri->first();
        $ultimo = $membri->last();
        $righe = $this->righeDopoGliAltri([(int) $primo->immobile_id], $membri);
        $persone = [];
        foreach ($membri as $m) {
            $riga = $m->riga_uscente_id !== null ? $righe->get((int) $m->riga_uscente_id) : null;
            if ($riga === null) {
                return [];
            }
            $fine = $riga->data_fine === null ? null : CarbonImmutable::parse(substr((string) $riga->data_fine, 0, 10));
            $persone[] = ['nome' => $this->nome($m, 'uscente') ?? 'chi esce', 'quota' => round((float) $riga->quota, 2), 'fine' => $fine];
        }
        // «al 50 %», «all'80 %»: la preposizione con l'articolo della percentuale.
        $al = fn (float $q) => preg_replace(['/^il /', '/^l\'/', '/^lo /'], ['al ', 'all\'', 'allo '], NudiDellEstinzione::percentuale($q));
        $finePer = fn (?CarbonImmutable $f) => $f === null ? ' senza data di fine' : ' fino al ' . $this->data($f);
        $stessaQuota = collect($persone)->pluck('quota')->unique()->count() === 1;
        $stessaFine = collect($persone)->map(fn (array $p) => $p['fine']?->toDateString())->unique()->count() === 1;
        $chi = $this->elenco(array_map(fn (array $p) => $p['nome'] . ($stessaQuota ? '' : ' ' . $al($p['quota'])) . ($stessaFine ? '' : $finePer($p['fine'])), $persone));
        $frase = $chi . (count($persone) > 1 ? ' tornano titolari' : ' torna titolare') . ($primo->riservaUsufrutto() ? ' della proprietà piena' : '')
            . ($stessaQuota ? ' ' . $al($persone[0]['quota']) : '') . ($stessaFine ? $finePer($persone[0]['fine']) : '')
            . ($primo->riservaUsufrutto() ? ', e l\'usufrutto tenuto con l\'atto si toglie' : '');

        // Chi era entrato: esce dall'unità, o torna alla parte che aveva prima (il terzo fratello), o com'era se aveva un altro ruolo.
        $entrante = $this->nome($ultimo, 'entrante');
        if ($entrante !== null && $ultimo->anagrafica_entrante_id !== null) {
            $sue = $righe->filter(fn (object $r) => (int) $r->anagrafica_id === (int) $ultimo->anagrafica_entrante_id);
            $stessoRuolo = $sue->filter(fn (object $r) => (string) $r->tipologia === (string) $ultimo->tipologia && $r->data_fine === null)->values();
            $frase .= '; ' . match (true) {
                $sue->isEmpty() => $entrante . ' esce dall\'unità',
                $stessoRuolo->count() === 1 => $entrante . ' torna ' . $al((float) $stessoRuolo[0]->quota),
                default => $entrante . ' torna com\'era prima dell\'atto',
            };
        }
        $frasi = [$frase . '.'];

        $pertinenze = $membri->flatMap(fn (Subentro $m) => $this->famiglia($m)->skip(1)->map(fn (Subentro $f) => (string) ($f->immobile?->nome ?? '')))
            ->filter()->unique()->values()->all();
        if ($pertinenze !== []) {
            $frasi[] = sprintf('Su %s le righe di titolarità scritte dai passaggi tornano come prima.', $this->elenco($pertinenze));
        }

        return $frasi;
    }

    /** Che cosa il passaggio ha toccato, contato come lo conta l'esito dell'annullamento. @return array<string, mixed> */
    private function contiDegliEffetti(Subentro $padre): array
    {
        $famiglia = $this->famiglia($padre);

        return ['rate' => $this->quoteDelRegistro($padre)['voci']->pluck('rata_id')->unique()->count(), 'saldi' => Saldo::whereIn('subentro_id', $famiglia->pluck('id'))->count(),
            'arretrato' => count($padre->saldiDellArretrato()), 'legate' => self::legateInParole(Saldo::where('passaggio_id', $padre->id)->orderBy('id')->get()),
            'interessi' => self::interessiInParole($padre), 'rimessi' => self::nomiDeiRimessi(self::daRimettere($padre))];
    }

    /**
     * Decisione 705: le frasi dell'esito per i passaggi registrati insieme, prima (`$fatto` falso, nella finestra dello storico) o dopo (nel messaggio):
     * quelle di ciascun passaggio, senza doppioni, con le righe di titolarità «scritte dai passaggi» e le righe del conguaglio contate tutte insieme
     * («Le 4 righe del conguaglio si tolgono dai saldi della gestione.»). Le quote delle rate passate restano una frase per persona: tornano a ciascuna.
     *
     * @param list<array{0: array<string, mixed>, 1: ?string, 2: ?string}> $parti per ogni passaggio i conti, chi esce e chi entra
     * @return list<string>
     */
    public static function frasiEffettiDellInsieme(array $parti, bool $fatto): array
    {
        $conguaglio = $fatto ? 'Il conguaglio è stato tolto dai saldi della gestione.' : 'Il conguaglio si toglie dai saldi della gestione.';
        $righeDelConguaglio = 0;
        $frasi = [];
        foreach ($parti as [$conti, $uscente, $entrante]) {
            foreach (self::frasiEffetti($conti, $uscente, $entrante, $fatto) as $frase) {
                if ($frase === $conguaglio) {
                    $righeDelConguaglio += (int) $conti['saldi'];
                }
                $frasi[] = $frase;
            }
        }
        $frasi = array_map(fn (string $f) => match ($f) {
            'Le righe di titolarità scritte dal passaggio tornano come prima.' => 'Le righe di titolarità scritte dai passaggi tornano come prima.',
            'Le righe di titolarità scritte dal passaggio sono tornate come prima.' => 'Le righe di titolarità scritte dai passaggi sono tornate come prima.',
            $conguaglio => sprintf('%s del conguaglio %s dai saldi della gestione.',
                $righeDelConguaglio === 1 ? 'La riga' : "Le {$righeDelConguaglio} righe",
                $fatto ? ($righeDelConguaglio === 1 ? 'è stata tolta' : 'sono state tolte') : ($righeDelConguaglio === 1 ? 'si toglie' : 'si tolgono')),
            default => $f,
        }, $frasi);
        $frasi = array_values(array_unique($frasi));
        // Prima le righe di titolarità, poi le quote tornate a ciascuna (nell'ordine dei passaggi), poi il resto: l'ordine della frase di un passaggio.
        $posto = fn (string $f) => match (true) {
            str_starts_with($f, 'Le righe di titolarità') => 0,
            str_starts_with($f, 'La quota di una rata') || str_starts_with($f, 'Le quote di ') => 1,
            default => 2,
        };
        usort($frasi, fn (string $a, string $b) => $posto($a) <=> $posto($b));

        return $frasi;
    }

    /**
     * Le frasi dell'esito, prima (`$fatto` falso, nel modulo) o dopo (nel messaggio di conferma): la stessa lettura. `$successione` non cambia la
     * preposizione, che è la stessa in ogni passaggio.
     *
     * @param array{rate: int, saldi: int, arretrato?: int, legate?: array{testo: string, plurale: bool}|null, interessi?: array{defunto: string, dal: string, eredi: ?string}|null} $conti
     * @return list<string>
     */
    public static function frasiEffetti(array $conti, ?string $uscente, ?string $entrante, bool $fatto, bool $successione = false): array
    {
        // «ad» davanti ai nomi che cominciano per A, in ogni passaggio, come in `descrivi`.
        $a = fn (string $nome) => FrasiObbligati::a($nome);
        // «Scritte dal passaggio»: una riga associata a mano dopo, che non si scontra con niente, resta (giro di verifica, G-4).
        $frasi = [$fatto ? 'Le righe di titolarità scritte dal passaggio sono tornate come prima.' : 'Le righe di titolarità scritte dal passaggio tornano come prima.'];
        $n = (int) $conti['rate'];
        if ($n > 0) {
            $frasi[] = sprintf('%s %s %s, con le regole di prima.',
                ($n === 1 ? 'La quota di una rata passata ' : "Le quote di {$n} rate passate ") . $a($entrante ?? 'chi è entrato'),
                $fatto ? ($n === 1 ? 'è tornata' : 'sono tornate') : ($n === 1 ? 'torna' : 'tornano'),
                $a($uscente ?? 'chi è uscito'));
        }
        // 1.11.0-beta.44: le righe dell'arretrato di una successione se ne vanno con il conguaglio, e si dice.
        $arretrato = (int) ($conti['arretrato'] ?? 0) > 0;
        $conguaglio = (int) $conti['saldi'] - (int) ($conti['arretrato'] ?? 0) > 0;
        if ($conguaglio || $arretrato) {
            $cosa = match (true) {
                $conguaglio && $arretrato => 'Il conguaglio e l\'arretrato del defunto',
                $arretrato => 'L\'arretrato del defunto',
                default => 'Il conguaglio',
            };
            $frasi[] = $cosa . ($fatto ? ($conguaglio && $arretrato ? ' sono stati tolti' : ' è stato tolto') : ($conguaglio && $arretrato ? ' si tolgono' : ' si toglie')) . ' dai saldi della gestione.';
        }
        // 1.11.0-beta.53 (Coda 244): i saldi del Wallet legati al passaggio si tolgono con lui, e si dicono con il verso a parole.
        if (! empty($conti['legate']['testo'])) {
            $piu = (bool) $conti['legate']['plurale'];
            $frasi[] = sprintf('%s %s: %s.', $piu ? 'I saldi del Wallet legati a questo passaggio' : 'Il saldo del Wallet legato a questo passaggio',
                $fatto ? ($piu ? 'sono stati tolti' : 'è stato tolto') : ($piu ? 'si tolgono' : 'si toglie'), $conti['legate']['testo']);
        }
        // Chi la vendita aveva tolto dalle persone del condominio torna fra loro.
        if (! empty($conti['rimessi']['testo'])) {
            $piu = (bool) $conti['rimessi']['plurale'];
            $frasi[] = $conti['rimessi']['testo'] . ($fatto ? ($piu ? ' sono di nuovo' : ' è di nuovo') : ($piu ? ' tornano' : ' torna')) . ' fra le persone del condominio.';
        }
        // D3 della prova della Coda 663: con «si fermano al decesso» la proposta del defunto torna a contare i giorni dopo il decesso, e quella degli eredi non
        // conta più il debito ereditato.
        if (! empty($conti['interessi'])) {
            $i = $conti['interessi'];
            $eredi = $i['eredi'] === null ? '' : sprintf(', e quella di %s non conta più il debito ereditato', $i['eredi']);
            $accordo = $i['accordo'] ?? null;
            // E7 della seconda prova della beta.12: l'accordo di rientro del defunto, in regola, si riapre con l'annullamento e tiene fuori i giorni dopo il
            // decesso delle rate che copre: la frase non promette giorni che la proposta non conterà.
            $frasi[] = match ($accordo['coperte'] ?? null) {
                'tutte' => sprintf('La proposta degli interessi di %s non conta i giorni dopo il decesso: l\'accordo di rientro del %s, che %s, li tiene fuori finché regge%s.',
                    $i['defunto'], self::giornoItaliano($accordo['del']), $fatto ? 'si è riaperto' : 'si riapre', $eredi),
                'in_parte' => sprintf('La proposta degli interessi di %s %s i giorni dopo il decesso, dal %s, sul debito fuori dall\'accordo di rientro del %s, che %s e tiene fuori il '
                    . 'resto finché regge%s.', $i['defunto'], $fatto ? 'conta di nuovo' : 'torna a contare', self::giornoItaliano($i['dal']), self::giornoItaliano($accordo['del']),
                    $fatto ? 'si è riaperto' : 'si riapre', $eredi),
                default => sprintf('La proposta degli interessi di %s %s i giorni dopo il decesso, dal %s%s.', $i['defunto'], $fatto ? 'conta di nuovo' : 'torna a contare',
                    self::giornoItaliano($i['dal']), $eredi),
            };
        }

        return $frasi;
    }

    /** Il giorno all'italiana, «17/11/2026». */
    private static function giornoItaliano(string $data): string
    {
        return substr($data, 8, 2) . '/' . substr($data, 5, 2) . '/' . substr($data, 0, 4);
    }

    /**
     * D3 della prova della Coda 663: con «si fermano al decesso», chi e da quando per la frase dell'annullamento; null con «continuano a suo nome» o per un
     * passaggio che non è una successione.
     *
     * E7 della seconda prova della beta.12: con l'accordo di rientro del defunto chiuso «in regola» dalla successione, l'annullamento lo riapre, e
     * l'accordo tiene fuori i giorni delle rate che copre. `accordo` dice se copre tutto il debito del defunto sulle unità del passaggio (`tutte`), una parte
     * (`in_parte`) o niente (null), e il giorno dell'accordo.
     *
     * @return array{defunto: string, dal: string, eredi: ?string, accordo: ?array{coperte: string, del: string}}|null
     */
    private static function interessiInParole(Subentro $padre): ?array
    {
        if (! $padre->successione() || $padre->interessi_del_defunto !== Subentro::INTERESSI_AL_DECESSO || $padre->decorrenza === null) {
            return null;
        }
        $eredi = array_values(array_map('strval', (array) ($padre->registro['nomi']['eredi'] ?? [])));

        return ['defunto' => (string) ($padre->registro['nomi']['uscente'] ?? $padre->uscente?->nome ?? 'il defunto'), 'dal' => $padre->decorrenza->format('Y-m-d'),
            'eredi' => $padre->arretratoAgliEredi() && $eredi !== [] ? \App\Services\Gestionale\Accessori\RegoleDegliEredi::elenco($eredi) : null,
            'accordo' => self::accordoCheSiRiapre($padre)];
    }

    /**
     * E7 della seconda prova della beta.12: gli accordi di rientro del defunto che la successione ha chiuso «in regola» (decisione 454) e che l'annullamento
     * riapre: quanto del debito del defunto sulle unità del passaggio coprono. `tutte` se coprono per intero ogni sua rata aperta lì, `in_parte` se ne coprono
     * solo una parte; null se non ce ne sono. Il giorno è quello del primo accordo.
     *
     * @return array{coperte: string, del: string}|null
     */
    private static function accordoCheSiRiapre(Subentro $padre): ?array
    {
        $ids = collect((array) ($padre->registro['arretrato']['accordi'] ?? []))
            ->filter(fn ($a) => ($a['scelta'] ?? \App\Models\Gestionale\AccordoRientro::SUCCESSIONE_IN_REGOLA) === \App\Models\Gestionale\AccordoRientro::SUCCESSIONE_IN_REGOLA)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return null;
        }
        // Riaperto, un accordo tiene fuori i giorni finché regge: oggi dentro il suo periodo, o tutto versato, e senza una decadenza registrata.
        $regola = app(\App\Services\Gestionale\Debiti\AccordiRientro::class);
        $oggi = \App\Helpers\DateHelper::oggiUtente();
        $accordi = \App\Models\Gestionale\AccordoRientro::with(['rate', 'scadenze'])->whereIn('id', $ids)->orderBy('data_accordo')->get()
            ->filter(fn (\App\Models\Gestionale\AccordoRientro $a) => $regola->reggevaIl($a, $oggi))->values();
        if ($accordi->isEmpty()) {
            return null;
        }
        $unita = Subentro::conAnnullati()->where(fn ($q) => $q->whereKey($padre->id)->orWhere('subentro_padre_id', $padre->id))->pluck('immobile_id')->map(fn ($id) => (int) $id)->all();
        $coperto = [];
        foreach ($accordi as $a) {
            foreach ($a->rate as $r) {
                $k = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($r->rata_id, $r->immobile_id);
                $coperto[$k] = ($coperto[$k] ?? 0) + (int) $r->importo_riconosciuto;
            }
        }
        $aperte = collect(app(\App\Services\Gestionale\Debiti\LetturaDebiti::class)->perPersona((int) $padre->condominio_id, (int) $padre->anagrafica_uscente_id)['rate'])
            ->filter(fn ($r) => (int) $r['residuo'] > 0 && in_array((int) ($r['immobile_id'] ?? 0), $unita, true));
        if ($aperte->isEmpty()) {
            return null;
        }
        $tutte = $aperte->every(fn ($r) => ($coperto[\App\Services\Gestionale\Debiti\AccordiRientro::chiave($r['rata_id'], $r['immobile_id'])] ?? 0) >= (int) $r['residuo']);
        $qualcuna = $aperte->contains(fn ($r) => isset($coperto[\App\Services\Gestionale\Debiti\AccordiRientro::chiave($r['rata_id'], $r['immobile_id'])]));
        if (! $qualcuna) {
            return null;
        }

        return ['coperte' => $tutte ? 'tutte' : 'in_parte', 'del' => $accordi->first()->data_accordo->format('Y-m-d')];
    }

    /**
     * Decisione 493 (7): un erede ha interessi sul debito ereditato con questa successione registrati e non annullati: la successione non si annulla, e la
     * frase dice quale addebito annullare prima. Null se non ce ne sono.
     */
    private static function interessiEreditatiRegistrati(Subentro $padre): ?string
    {
        $eredi = array_values(array_filter(array_map(fn ($e) => (int) ($e['anagrafica_id'] ?? 0), (array) ($padre->registro['eredi'] ?? []))));
        if (! $padre->successione() || $eredi === []) {
            return null;
        }
        $quote = \App\Models\Gestionale\RataQuote::with(['rata', 'anagrafica:id,nome'])->whereIn('anagrafica_id', $eredi)->where('tipo', \App\Models\Gestionale\RataQuote::TIPO_ACCESSORIO)
            ->whereIn('rata_id', DB::table('rate')->whereIn('piano_rate_id', PianoRate::degliAddebiti()->where('condominio_id', $padre->condominio_id)->select('id'))->select('id'))
            ->orderBy('id')->get();
        foreach ($quote as $q) {
            $d = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($q);
            $diQuesta = collect($d['interessi']['righe'] ?? [])->contains(fn ($r) => (int) ($r['ereditata']['subentro_id'] ?? 0) === (int) $padre->id);
            if (($d['tipo'] ?? null) === 'interessi' && $diQuesta) {
                return sprintf('%s ha interessi sul debito ereditato con questa successione già registrati: «%s» (%s). Annulla prima quell\'addebito dal suo estratto '
                    . 'conto, poi torna qui.', $q->anagrafica?->nome ?? 'Un erede', trim((string) ($q->rata?->descrizione ?? 'Interessi di mora')),
                    \App\Helpers\MoneyHelper::format((int) $q->importo));
            }
        }

        return null;
    }

    /**
     * Decisione 690 (3), Coda 708: un erede ha già versato sul debito ereditato con questa successione, e l'incasso non è stornato: la successione non si
     * annulla, e la frase dice chi, quanto e quale incasso stornare prima. Annullarla toglierebbe la coppia del Wallet e lascerebbe il denaro dell'erede
     * sulla rata del defunto, che tornerebbe debitore pagato da un altro senza che nessuna riga lo dica.
     *
     * Si legge il registro della successione (`versamenti_degli_eredi`, scritto da `VersamentoDegliEredi`): le voci non stornate con il giorno del versamento
     * dal decesso in poi, che sono la strada «Debito ereditato da …» dell'erede pagante e quella «per il debito degli eredi» dal conto del defunto (con o senza
     * «Versato da»), le cui righe portano il legame agli eredi. Una voce con il giorno di prima del decesso (un bonifico del defunto registrato dopo la
     * successione) non ferma: è solo la coppia per quota, senza legami, e l'annullamento la toglie con le righe legate; il versamento resta del defunto.
     * Con la catena (decisione 530) le coppie degli eredi di un erede stanno nel registro della seconda successione, che si annulla per prima.
     *
     * Chi ha pagato si dice come lo dice il dettaglio dell'incasso (`VersamentoDegliEredi::deiVersamenti`, `erede`): un erede solo, con tutte le righe sulla
     * rata del defunto legate a lui; altrimenti il versamento è «registrato per gli eredi». Null se non ce ne sono.
     */
    private static function versamentiDegliErediRegistrati(Subentro $padre): ?string
    {
        if (! $padre->successione() || $padre->decorrenza === null) {
            return null;
        }
        $decesso = $padre->decorrenza->format('Y-m-d');
        $voci = collect((array) ($padre->registro['versamenti_degli_eredi'] ?? []))
            ->filter(fn ($v) => ($v['stornato_il'] ?? null) === null && (array) ($v['coppie'] ?? []) !== []
                && (($v['il'] ?? null) === null || (string) $v['il'] >= $decesso))
            ->values();
        if ($voci->isEmpty()) {
            return null;
        }
        $ids = $voci->map(fn ($v) => (int) ($v['scrittura_id'] ?? 0))->unique()->values()->all();
        $incassi = \App\Models\Gestionale\ScritturaContabile::whereIn('id', $ids ?: [0])->get(['id', 'numero_protocollo', 'data_competenza'])->keyBy('id');
        $chiHaPagato = app(\App\Services\Gestionale\Accessori\VersamentoDegliEredi::class)->deiVersamenti($ids);
        $nomi = \App\Models\Anagrafica::whereIn('id', $voci->flatMap(fn ($v) => array_map(fn ($c) => (int) ($c['erede'] ?? 0), (array) $v['coppie']))->unique()->values()->all() ?: [0])
            ->pluck('nome', 'id');

        $versamenti = $voci->map(function ($v) use ($incassi, $chiHaPagato, $nomi) {
            $id = (int) ($v['scrittura_id'] ?? 0);
            $incasso = $incassi->get($id);
            $giorno = $incasso?->data_competenza?->format('Y-m-d') ?? (string) ($v['il'] ?? '');
            $protocollo = trim((string) ($incasso?->numero_protocollo ?? ''));
            $erede = $chiHaPagato[$id]['erede'] ?? null;

            return [
                'cifra' => \App\Helpers\MoneyHelper::format((int) array_sum(array_map(fn ($c) => (int) ($c['importo'] ?? 0), (array) $v['coppie']))),
                'incasso' => 'incasso ' . ($protocollo !== '' ? $protocollo . ' ' : '') . ($giorno !== '' ? 'del ' . self::giornoItaliano($giorno) : ''),
                'erede' => $erede !== null ? (string) ($nomi[(int) $erede] ?? $chiHaPagato[$id]['nome_erede'] ?? 'Un erede') : null,
                'eredi' => \App\Services\Gestionale\Accessori\RegoleDegliEredi::elenco(array_values(array_unique(array_map(fn ($c) => (string) ($nomi[(int) ($c['erede'] ?? 0)] ?? ''),
                    (array) $v['coppie'])))),
            ];
        });

        if ($versamenti->count() === 1) {
            $x = $versamenti->first();

            return $x['erede'] !== null
                ? sprintf('%s ha già versato %s sul debito ereditato con questa successione (%s). Storna prima il suo incasso dall\'elenco degli incassi, poi torna qui.',
                    $x['erede'], $x['cifra'], trim($x['incasso']))
                : sprintf('Sul debito ereditato con questa successione c\'è un versamento di %s registrato per gli eredi %s (%s). Storna prima quell\'incasso '
                    . 'dall\'elenco degli incassi, poi torna qui.', $x['cifra'], $x['eredi'], trim($x['incasso']));
        }

        return sprintf('Gli eredi hanno già versato sul debito ereditato con questa successione: %s. Storna prima quegli incassi dall\'elenco degli incassi, poi '
            . 'torna qui.', $versamenti->map(fn ($x) => $x['erede'] !== null
                ? sprintf('%s %s (%s)', $x['erede'], $x['cifra'], trim($x['incasso']))
                : sprintf('%s per gli eredi %s (%s)', $x['cifra'], $x['eredi'], trim($x['incasso'])))->implode(', '));
    }

    /**
     * D3 della prova della Coda 663: gli avvisi degli interessi del defunto, prima di annullare una successione con «si fermano al decesso». Gli interessi
     * registrati con quella scelta restano come sono (la proposta dopo l'annullamento offre i giorni dal decesso); quelli passati agli eredi con la
     * registrazione dopo la successione (decisione 493 (13)) tornano a suo nome.
     *
     * @return list<string>
     */
    private static function avvisiDegliInteressi(Subentro $padre): array
    {
        if (! $padre->successione() || $padre->interessi_del_defunto !== Subentro::INTERESSI_AL_DECESSO || $padre->decorrenza === null) {
            return [];
        }
        $defunto = (int) $padre->anagrafica_uscente_id;
        $nome = (string) ($padre->registro['nomi']['uscente'] ?? $padre->uscente?->nome ?? 'il defunto');
        $decesso = $padre->decorrenza->format('Y-m-d');
        $ultimo = CarbonImmutable::parse($decesso, 'UTC')->subDay()->format('Y-m-d');
        $unita = Subentro::conAnnullati()->where(fn ($q) => $q->whereKey($padre->id)->orWhere('subentro_padre_id', $padre->id))->pluck('immobile_id')->map(fn ($id) => (int) $id)->all();
        $passati = collect((array) ($padre->registro['arretrato']['accessori'] ?? []))->filter(fn ($a) => isset($a['dopo_la_successione']));
        $quotePassate = $passati->pluck('quota_id')->map(fn ($id) => (int) $id)->all();
        $avvisi = [];
        $registrati = [];
        $quote = \App\Models\Gestionale\RataQuote::with('rata')->where('anagrafica_id', $defunto)->where('tipo', \App\Models\Gestionale\RataQuote::TIPO_ACCESSORIO)
            ->whereIn('rata_id', DB::table('rate')->whereIn('piano_rate_id', PianoRate::degliAddebiti()->where('condominio_id', $padre->condominio_id)->select('id'))->select('id'))
            ->orderBy('id')->get();
        foreach ($quote as $q) {
            $d = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($q);
            if (($d['tipo'] ?? null) !== 'interessi' || in_array((int) $q->id, $quotePassate, true)) {
                continue;
            }
            $ferme = collect($d['interessi']['righe'] ?? [])->filter(fn ($r) => in_array((int) ($r['immobile_id'] ?? 0), $unita, true) && ($r['al'] ?? null) === $ultimo
                && (string) ($d['interessi']['alla'] ?? '') > $ultimo);
            if ($ferme->isNotEmpty()) {
                // E6 della seconda prova della beta.12: la cifra dell'addebito, quella che l'amministratore trova nell'estratto conto, non la somma delle sole
                // righe ferme al decesso (una riga ferma prima per un accordo di rientro è nello stesso addebito).
                $registrati[] = sprintf('«%s», %s', trim((string) ($q->rata?->descrizione ?? 'Interessi di mora')), \App\Helpers\MoneyHelper::format((int) $q->importo));
            }
        }
        if ($registrati !== []) {
            $avvisi[] = sprintf('Gli interessi di %s registrati con la scelta «si fermano al decesso» (%s, fino al %s) restano come sono: dopo l\'annullamento la proposta '
                . 'offre i giorni dal %s.', $nome, implode('; ', $registrati), self::giornoItaliano($ultimo), self::giornoItaliano($decesso));
        }
        if ($passati->isNotEmpty()) {
            $avvisi[] = sprintf('Gli interessi di %s fino al decesso registrati dopo la successione e passati agli eredi (%s) tornano a suo nome.', $nome,
                \App\Helpers\MoneyHelper::format((int) $passati->sum(fn ($a) => (int) ($a['importo'] ?? 0))));
        }

        return $avvisi;
    }

    /**
     * Le righe legate al passaggio, nelle parole degli effetti: «€ 60,16 a credito di Ugo e € 60,16 a debito di Elsa (gestione Ordinaria 2026)»;
     * null se non ce ne sono (una riga a zero non si dice).
     *
     * @param Collection<int, Saldo> $legate
     * @return array{testo: string, plurale: bool}|null
     */
    private static function legateInParole(Collection $legate): ?array
    {
        $dette = $legate->filter(fn (Saldo $x) => (int) $x->saldo_iniziale !== 0)->values()->load(['anagrafica:id,nome', 'gestione:id,nome']);

        return $dette->isEmpty() ? null : ['testo' => FermataDopoScrivi::righeInParole($dette), 'plurale' => $dette->count() > 1];
    }

    /**
     * Decisione 705 (1.12.0-beta.14): un passaggio registrato insieme ad altri (più persone che escono con lo stesso atto) si annulla con tutti quelli
     * dell'insieme, dall'id di uno qualsiasi ({@see annullaLInsieme()}). `passaggi_annullati` dice quanti; `insieme` è nullo per un passaggio da solo.
     *
     * @return array{righe: int, rate: int, saldi: int, arretrato: int, avvisi: list<string>, uscente: ?string, entrante: ?string, successione: bool, legate: array{testo: string, plurale: bool}|null, passaggi_annullati: int, insieme: ?array{passaggi: list<int>, nomi: list<string>, frasi: list<string>}}
     */
    public function execute(Subentro $passaggio, string $nota, User $utente): array
    {
        $nota = trim($nota);
        if (mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota_annullamento' => 'Scrivi in almeno dieci caratteri perché annulli il passaggio: resta nello storico.']);
        }

        $padreId = (int) ($passaggio->subentro_padre_id ?? $passaggio->id);
        $padre = Subentro::conAnnullati()->findOrFail($padreId);
        if (! $padre->annullato() && ($membri = $this->membriDaAnnullare($padre))->count() > 1) {
            return $this->annullaLInsieme($membri, $nota, $utente);
        }

        // Tre tentativi: un'emissione di più rate può prendere le quote dell'unità in un ordine diverso, e InnoDB scioglie
        // l'incrocio annullando una delle due transazioni. Il corpo scrive solo nel database, e rifarlo è sicuro.
        // Gesto 4 all'indietro: una riga sola, con il motivo scritto.
        $prep = $this->prepara($padreId, $nota);
        $esito = DB::transaction(fn () => $this->annullaUno($prep, $nota, $utente, false), 3);

        // Il contatore dei promemoria dello staff è in cache per dieci minuti: il promemoria tolto non deve contare ancora.
        InboxService::clearAdminCache();

        return $esito + ['passaggi_annullati' => 1, 'insieme' => null];
    }

    /**
     * Il passaggio e le sue pertinenze, letti prima della transazione: le righe figlie nascono solo con il padre, nella transazione della
     * registrazione, e le loro unità non cambiano più. Con il gesto 4 all'indietro, che legge l'unità: anche lui prima della transazione.
     *
     * @return array{padre_id: int, ids: list<int>, unita: list<int>, gesto: \App\Services\RegistroModifiche\Gesto}
     */
    private function prepara(int $padreId, string $nota): array
    {
        $famigliaUnita = Subentro::conAnnullati()->where(fn ($q) => $q->whereKey($padreId)->orWhere('subentro_padre_id', $padreId))->pluck('immobile_id', 'id');

        return [
            'padre_id' => $padreId,
            'ids' => $famigliaUnita->keys()->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'unita' => $famigliaUnita->values()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
            'gesto' => GestiDelRegistro::passaggioAnnullato(Subentro::conAnnullati()->findOrFail($padreId), $nota),
        ];
    }

    /**
     * Decisione 705: i passaggi registrati insieme si annullano tutti insieme, con un motivo solo, **dall'ultimo registrato al primo**, nella stessa
     * transazione e sotto una riga sola del registro delle modifiche ({@see GestiDelRegistro::passaggiAnnullatiInsieme()}): ognuno con il suo
     * annullamento di oggi ({@see annullaUno()}), che fa i suoi controlli sullo stato lasciato da quelli già annullati. È esatto per costruzione, come la
     * registrazione: un passaggio legge solo ciò che è registrato prima di lui. Un rifiuto in uno qualsiasi annulla tutto, con il nome della persona
     * davanti («La parte di Marta Acciaioli: …»); un passaggio registrato dopo l'insieme lo ferma con la frase di oggi, che è dell'insieme.
     *
     * I lock sono quelli di oggi, presi **per tutti i passaggi prima di ogni lettura senza lock**: su MySQL l'istantanea nasce alla prima lettura
     * semplice, e i lock del secondo presi dopo le letture del primo non gli farebbero vedere ciò che è stato scritto nel frattempo.
     *
     * @param Collection<int, Subentro> $membri i passaggi dell'insieme, nell'ordine in cui sono stati scritti
     */
    private function annullaLInsieme(Collection $membri, string $nota, User $utente): array
    {
        $membri = $membri->sortBy('id')->values();
        $preps = $membri->sortByDesc('id')->map(fn (Subentro $m) => $this->prepara((int) $m->id, $nota))->values()->all();
        $unita = collect($preps)->flatMap(fn (array $p) => $p['unita'])->unique()->sort()->values()->all();
        $ids = collect($preps)->flatMap(fn (array $p) => $p['ids'])->unique()->sort()->values()->all();
        $padri = collect($preps)->pluck('padre_id')->sort()->values()->all();
        $nomi = $membri->mapWithKeys(fn (Subentro $m) => [(int) $m->id => (string) ($this->nome($m, 'uscente') ?? 'chi esce')])->all();
        $propri = $this->nomiPropri($membri);
        $gesto = GestiDelRegistro::passaggiAnnullatiInsieme($membri->all(), $nota);

        $esito = DB::transaction(fn () => RegistroModifiche::durante($gesto, function () use ($preps, $unita, $ids, $padri, $nota, $utente, $nomi, $propri, $membri) {
            // 0. I lock di tutti, prima di ogni lettura senza lock (vedi `annullaUno`). Ognuno li riprende per sé: sono già suoi.
            ['bloccati' => $bloccati] = $this->blocca($unita, $ids, $padri);
            if ($bloccati->only($padri)->contains(fn (Subentro $s) => $s->annullato())) {
                throw ValidationException::withMessages(['passaggio' => 'Questo passaggio è già stato annullato.']);
            }
            // Un passaggio registrato dopo l'insieme, su una delle sue unità: si annulla prima quello, con la frase di oggi.
            $famiglie = Subentro::conAnnullati()->with('immobile:id,nome')->whereIn('id', $ids)->orderBy('id')->get();
            if ($motivo = $this->passaggioDopo($famiglie, $ids)) {
                throw ValidationException::withMessages(['passaggio' => $motivo]);
            }

            $esiti = [];
            foreach ($preps as $prep) {
                try {
                    // Il gesto di ciascuno si conta nel lotto dell'insieme.
                    $esiti[(int) $prep['padre_id']] = $this->annullaUno($prep, $nota, $utente, true);
                } catch (ValidationException $e) {
                    throw self::conLaParte($e, $nomi[(int) $prep['padre_id']] ?? 'chi esce', $propri);
                }
            }

            return $this->esitoDellInsieme($esiti, $membri);
        }), 3);

        InboxService::clearAdminCache();

        return $esito;
    }

    /**
     * L'esito dell'insieme nella forma di quello di un passaggio (le somme, chi esce in tutti), più `passaggi_annullati` e `insieme`: gli id dei
     * passaggi, i nomi di chi esce e le frasi dell'esito di tutti ({@see frasiEffettiDellInsieme()}), che il messaggio dice al posto di quelle di uno.
     *
     * @param array<int, array<string, mixed>> $esiti per id del passaggio
     * @param Collection<int, Subentro> $membri
     */
    private function esitoDellInsieme(array $esiti, Collection $membri): array
    {
        $inOrdine = $membri->map(fn (Subentro $m) => $esiti[(int) $m->id])->values();
        $somma = fn (string $chiave) => (int) $inOrdine->sum(fn (array $e) => (int) ($e[$chiave] ?? 0));
        $nomi = $inOrdine->map(fn (array $e) => (string) ($e['uscente'] ?? 'chi esce'))->all();

        return [
            'righe' => $somma('righe'), 'rate' => $somma('rate'), 'saldi' => $somma('saldi'), 'arretrato' => $somma('arretrato'),
            'avvisi' => array_values(array_unique(array_merge(...array_map(fn (array $e) => (array) $e['avvisi'], array_values($esiti))))),
            'uscente' => $this->elenco($nomi), 'entrante' => $inOrdine->first()['entrante'] ?? null,
            'successione' => false, 'legate' => null, 'interessi' => null,
            'passaggi_annullati' => $inOrdine->count(),
            'insieme' => [
                'passaggi' => $membri->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'nomi' => $nomi,
                'frasi' => self::frasiEffettiDellInsieme($inOrdine->map(fn (array $e) => [$e, $e['uscente'] ?? null, $e['entrante'] ?? null])->all(), true),
            ],
        ];
    }

    /** Il rifiuto di un passaggio dell'insieme, con il nome della persona davanti, sulle stesse chiavi. @param list<string> $propri */
    private static function conLaParte(ValidationException $e, string $nome, array $propri): ValidationException
    {
        $messaggi = [];
        foreach ($e->errors() as $chiave => $frasi) {
            foreach ((array) $frasi as $frase) {
                $messaggi[$chiave][] = self::fraseDellaParte($nome, (string) $frase, $propri);
            }
        }

        return ValidationException::withMessages($messaggi);
    }

    /**
     * «La parte di Marta Acciaioli: il conguaglio di questo passaggio …». La frase che comincia con un nome proprio (una persona, un'unità) lo tiene
     * maiuscolo. Le parole della bozza approvata (`docs/design/beta14_piu_venditori_bozza.html`), come la registrazione dell'insieme.
     *
     * @param list<string> $propri
     */
    private static function fraseDellaParte(string $nome, string $frase, array $propri): string
    {
        $proprio = collect($propri)->contains(fn (string $p) => $p !== '' && str_starts_with($frase, $p));

        return 'La parte di ' . $nome . ': ' . ($proprio ? $frase : mb_lcfirst($frase));
    }

    /** I nomi propri con cui un motivo può cominciare: le persone dei passaggi e le loro unità. @param Collection<int, Subentro> $membri @return list<string> */
    private function nomiPropri(Collection $membri): array
    {
        $nomi = [];
        foreach ($membri as $m) {
            array_push($nomi, $this->nome($m, 'uscente'), $this->nome($m, 'entrante'), ...$this->famiglia($m)->map(fn (Subentro $s) => $s->immobile?->nome)->all());
        }

        return array_values(array_unique(array_filter(array_map(fn ($n) => $n === null ? '' : (string) $n, $nomi))));
    }

    /**
     * I lock dell'annullamento, PRIMA di qualunque lettura senza lock. Su MySQL (REPEATABLE READ) l'istantanea delle letture nasce
     * alla prima SELECT semplice: presi dopo, i lock non farebbero vedere ai controlli un passaggio, un'emissione
     * o un «Associa» appena committati, e le scritture qui sotto li sovrascriverebbero (Fase 1-bis della beta.37,
     * A2, riprodotto su MySQL). Prima l'unità, nello stesso ordine di `RegistraSubentroAction`: con i passaggi
     * bloccati per primi (e l'intervallo dell'indice dei figli con loro) una registrazione in volo sulla stessa
     * unità si incrociava con l'annullamento, e MySQL ne uccideva uno (1213, giro di verifica, C-R1, riprodotto).
     * Poi i passaggi per chiave primaria, e le righe, le quote e i saldi dell'unità, che scrivono anche
     * «Associa», l'emissione e l'incasso. Nessun `orderBy` sulle letture bloccanti: InnoDB blocca nell'ordine
     * dell'indice che usa, e un ordinamento può fargli scegliere la chiave primaria e bloccare tutta la tabella.
     *
     * Decisione 705: per i passaggi registrati insieme le unità, i passaggi e le persone di tutti, in una volta.
     *
     * @param list<int> $unita
     * @param list<int> $idsFamiglia
     * @param list<int> $padriIds
     * @return array{bloccati: Collection<int, Subentro>, saldi: Collection<int, Saldo>, legate: Collection<int, Saldo>}
     */
    private function blocca(array $unita, array $idsFamiglia, array $padriIds): array
    {
        Immobile::bloccaPerScrivere($unita);
        $bloccati = Subentro::conAnnullati()->whereIn('id', $idsFamiglia)->lockForUpdate()->get()->keyBy('id');
        $padri = collect($padriIds)->map(fn (int $id) => $bloccati->get($id) ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Subentro::class, [$id]));
        $voci = $padri->flatMap(fn (Subentro $p) => (array) ($p->registro['quote'] ?? []));
        DB::table('anagrafica_immobile')->whereIn('immobile_id', $unita)->lockForUpdate()->pluck('id');
        DB::table('rate_quote')->whereIn('immobile_id', $unita)->lockForUpdate()->pluck('id');
        DB::table('rate_quote')->whereIn('id', $voci->pluck('id')->merge($voci->pluck('gemella_id'))->filter()->all())->lockForUpdate()->pluck('id');
        // I promemoria delle rate di chi esce e di chi entra, che `seguonoLeQuote` riscrive e che il controllo delle
        // segnalazioni legge: una segnalazione dal portale in volo aspetta, e poi rilegge (C-R5). Dalla tabella ponte
        // e per chiave, con letture bloccanti: una lettura semplice qui fisserebbe l'istantanea troppo presto.
        // Nella successione anche gli eredi: i promemoria degli interessi e delle spese passati a loro si riscrivono (Coda 652).
        $persone = array_values(array_unique(array_filter($padri->flatMap(fn (Subentro $p) => [(int) $p->anagrafica_uscente_id, (int) $p->anagrafica_entrante_id,
            ...array_map(fn ($e) => (int) ($e['anagrafica_id'] ?? 0), (array) ($p->registro['eredi'] ?? []))])->all())));
        // Solo le chiavi dall'indice della persona, poi per chiave primaria: chiedere `evento_id` all'indice della
        // persona fa scegliere a MySQL la scansione dell'indice unico, che sotto lock bloccherebbe tutta la tabella
        // (verificato con EXPLAIN su MySQL 8.4, giro di verifica C-R6).
        $ponte = DB::table('anagrafica_evento')->whereIn('anagrafica_id', $persone)->lockForUpdate()->pluck('id')->all();
        $promemoria = DB::table('anagrafica_evento')->whereIn('id', $ponte)->lockForUpdate()->pluck('evento_id')->all();
        DB::table('eventi')->whereIn('id', $promemoria)->lockForUpdate()->pluck('id');
        $saldi = Saldo::whereIn('subentro_id', $idsFamiglia)->lockForUpdate()->with('pianoRate')->get();
        // 1.11.0-beta.53 (Coda 244): i saldi del Wallet legati al passaggio, dopo le righe che il passaggio ha scritto.
        $legate = Saldo::whereIn('passaggio_id', $padriIds)->lockForUpdate()->get();

        return ['bloccati' => $bloccati, 'saldi' => $saldi, 'legate' => $legate];
    }

    /**
     * Il lavoro dell'annullamento di un passaggio, nel suo gesto (gesto 4 all'indietro) e nella transazione di chi chiama: i lock, i controlli, le
     * scritture. Da solo
     * (`execute`) o come uno dell'insieme (`annullaLInsieme`, decisione 705): lì ogni passaggio si legge da solo con `motivoDelPassaggio`, sullo stato
     * lasciato da quelli già annullati, e il motivo dell'insieme resta allo storico.
     *
     * @param array{padre_id: int, ids: list<int>, unita: list<int>} $prep
     * @return array<string, mixed>
     */
    private function annullaUno(array $prep, string $nota, User $utente, bool $nellInsieme): array
    {
        ['padre_id' => $padreId, 'ids' => $idsFamiglia, 'unita' => $unita] = $prep;

        return RegistroModifiche::durante($prep['gesto'], function () use ($padreId, $idsFamiglia, $unita, $nota, $utente, $nellInsieme) {
            // 0. I lock, PRIMA di qualunque lettura senza lock (`blocca`).
            ['bloccati' => $bloccati, 'saldi' => $saldi, 'legate' => $legate] = $this->blocca($unita, $idsFamiglia, [$padreId]);
            $padre = $bloccati->get($padreId);
            $voci = collect($padre->registro['quote'] ?? []);

            $famiglia = $this->famiglia($padre);
            $motivo = match (true) {
                $padre->annullato() => 'Questo passaggio è già stato annullato.',
                $nellInsieme => $this->motivoDelPassaggio($padre),
                default => $this->motivoBlocco($padre),
            };
            if ($motivo) {
                throw ValidationException::withMessages(['passaggio' => $motivo]);
            }
            $avvisi = $this->avvisiDelPassaggio($padre);
            // D3: la frase della proposta del defunto, letta prima che l'annullamento tolga la scelta.
            $interessi = self::interessiInParole($padre);

            // 1. Il conguaglio: via tutte le righe del passaggio e delle pertinenze (la FK `saldi.subentro_id` è restrict).
            //    Riletto sulle righe bloccate, come `AnnullaConguaglioAction`.
            $assorbite = $saldi->filter(fn (Saldo $x) => (bool) $x->is_applicato);
            if ($assorbite->isNotEmpty()) {
                throw ValidationException::withMessages(['passaggio' => AnnullaConguaglioAction::fraseAssorbite($assorbite, self::aperturaAssorbite($padre, $assorbite),
                    'torna qui', perIlPassaggio: true, presiDaSeguire: $this->presiDaSeguire($famiglia), conArretrato: $padre->arretratoAgliEredi())]);
            }
            if ((int) $saldi->sum('saldo_iniziale') !== 0) {
                throw ValidationException::withMessages(['passaggio' => 'Le righe del conguaglio di questo passaggio non sommano zero: qualcosa è stato modificato a mano. Controlla i saldi della gestione prima di annullare.']);
            }
            Saldo::whereIn('id', $saldi->pluck('id'))->delete();

            //    Gli interessi e le spese senza fattura passati agli eredi tornano al defunto, finché nessun erede li ha versati.
            $accessori = (array) ($padre->registro['arretrato']['accessori'] ?? []);
            if (($motivo = AccessoriAgliEredi::motivo($accessori)) !== null) {
                throw ValidationException::withMessages(['passaggio' => $motivo]);
            }
            //    I loro promemoria tornano con le quote (Coda 652): letti prima, finché le quote degli eredi ci sono. Quello del defunto con un pagamento
            //    segnalato e non verificato resta «segnalato» (decisione 448).
            $promemoriaDegliAccessori = AccessoriAgliEredi::promemoriaDaRiscrivere($accessori);
            AccessoriAgliEredi::annulla($accessori);
            if ($promemoriaDegliAccessori['rate'] !== []) {
                AccessoriAgliEredi::riallineaPromemoria($promemoriaDegliAccessori['rate'], $promemoriaDegliAccessori['persone'], (int) $padre->anagrafica_uscente_id, $utente);
            }
            //    Gli accordi di rientro del defunto chiusi per la successione si riaprono.
            \App\Services\Subentro\AccordiDellaSuccessione::riapri((array) ($padre->registro['arretrato']['accordi'] ?? []));

            //    Poi i saldi del Wallet legati al passaggio, bloccati qui sopra: un'assorbita l'ha già fermato `motivoBlocco`, letto dopo i blocchi
            //    nella stessa transazione; le libere si tolgono, anche una gamba sola (sono righe scritte a mano, non una coppia che deve sommare
            //    zero), e il registro le conserva.
            $legateInParole = self::legateInParole($legate);
            $legateTolte = $legate->map(fn (Saldo $x) => ['id' => (int) $x->id, 'anagrafica_id' => $x->anagrafica_id === null ? null : (int) $x->anagrafica_id, 'gestione_id' => (int) $x->gestione_id,
                'immobile_id' => (int) $x->immobile_id, 'esercizio_id' => (int) $x->esercizio_id, 'saldo_iniziale' => (int) $x->saldo_iniziale])->values()->all();
            Saldo::whereIn('id', $legate->pluck('id'))->delete();

            // 2. Le bozze passate a chi è entrato tornano com'erano; la quota del pregresso rimasta a chi esce si riunisce.
            //    Una bozza sparita col suo piano (ricalcolato o eliminato dopo) non ha niente da rimettere: si salta, e non
            //    si conta; la riconosce la traccia del passaggio dentro la quota, non l'id (`quoteDelRegistro`, MySQL 5.7).
            //    L'UPDATE è condizionato allo stato di dopo: se tocca zero righe qualcosa è cambiato, e si torna indietro
            //    per intero.
            ['voci' => $esistenti, 'gemelle' => $gemelle] = $this->quoteDelRegistro($padre);
            $rateToccate = [];
            foreach (array_reverse($voci->all()) as $voce) {
                if (! $esistenti->has((int) $voce['id'])) {
                    continue;
                }
                if (! empty($voce['gemella_id']) && $gemelle->has((int) $voce['gemella_id'])) {
                    // DC4, la terza sede (decisione 75.3, 1.11.0-beta.49): i legami della gemella del pregresso con le scritture
                    // (una compensazione o un rimborso stornati, la pivot a somma zero) passano alla quota con cui si riunisce,
                    // invece di andarsene a cascata con lei. La quota equivalente qui è certa: è quella da cui la gemella è nata.
                    DB::table('quota_scrittura')->where('rate_quota_id', $voce['gemella_id'])->update(['rate_quota_id' => (int) $voce['id']]);
                    DB::table('rate_quote')->where('id', $voce['gemella_id'])->delete();
                }
                $rimessa = DB::table('rate_quote')->where('id', $voce['id'])
                    ->where('anagrafica_id', $voce['dopo']['anagrafica_id'])->where('importo', $voce['dopo']['importo'])
                    ->whereNull('scrittura_contabile_id')->where('importo_pagato', 0)
                    ->update([
                        'anagrafica_id'  => $voce['prima']['anagrafica_id'],
                        'importo'        => $voce['prima']['importo'],
                        'stato'          => $voce['prima']['stato'],
                        'regole_calcolo' => $voce['prima']['regole_calcolo'],
                        'updated_at'     => now(),
                    ]);
                if ($rimessa !== 1) {
                    throw ValidationException::withMessages(['passaggio' => 'Mentre annullavi è cambiata una quota passata con questo passaggio: ricarica la pagina e riprova.']);
                }
                $rateToccate[(int) $voce['rata_id']] = true;
            }

            // 3. Le righe di titolarità, unità per unità, al contrario.
            $righe = 0;
            foreach ($famiglia as $s) {
                foreach (array_reverse($s->registro['righe'] ?? []) as $op) {
                    match ($op['operazione']) {
                        'aperta' => DB::table('anagrafica_immobile')->where('id', $op['id'])->delete(),
                        'chiusa' => DB::table('anagrafica_immobile')->where('id', $op['id'])->update(['data_fine' => $op['prima']['data_fine'], 'updated_at' => now()]),
                        'modificata' => DB::table('anagrafica_immobile')->where('id', $op['id'])->update($op['prima'] + ['updated_at' => now()]),
                    };
                    $righe++;
                }
            }

            //    Chi la vendita aveva tolto dalle persone del condominio torna fra loro.
            $rimessi = self::rimetti($padre);

            // 4. I promemoria delle rate nel portale seguono di nuovo le quote; il promemoria in agenda del passaggio sparisce.
            if ($rateToccate !== []) {
                $this->eventi->seguonoLeQuote(array_keys($rateToccate), array_values(array_filter([(int) $padre->anagrafica_uscente_id, (int) $padre->anagrafica_entrante_id])), $utente);
            }
            Evento::where('eventable_type', Subentro::class)->whereIn('eventable_id', $idsFamiglia)->delete();

            // 5. Il passaggio resta, annullato. Il nome di chi annulla si copia nel registro, come quelli delle parti: la
            //    chiave esterna è `nullOnDelete`, e un utente che sparisce non deve cancellare la traccia.
            $uscente = $this->nome($padre, 'uscente');
            $entrante = $this->nome($padre, 'entrante');
            // Il fatto dell'annullamento (1.11.0-beta.51, §6.3; il dato si scrive, non si deriva): il più alto id di
            // quota di questo momento. Le quote più alte nascono da un ricalcolo fatto dopo, con la titolarità di prima, e non sono
            // stantie; quelle fino a qui, nate dopo il passaggio, hanno la titolarità del passaggio che non c'è più, e il piano non si
            // emette senza ricalcolo (`PianoRate::passaggiAnnullatiDaSeguire`). Letto qui, dopo i lock sulle quote dell'unità e con il
            // ricalcolo serializzato da `Immobile::bloccaDelCondominio`. Non c'è migrazione: il registro è JSON.
            $quotaMax = (int) DB::table('rate_quote')->max('id');
            foreach ($famiglia as $s) {
                $registro = $s->registro;
                $registro['nomi']['annullato_da'] = $utente->name;
                // I saldi legati tolti, sul passaggio padre: una cancellazione si lascia ispezionare.
                $registro['annullamento'] = ['quota_max_id' => $quotaMax] + ((int) $s->id === $padreId && $legateTolte !== [] ? ['saldi_legati_tolti' => $legateTolte] : []);
                // Decisione 483: la scelta sugli interessi del defunto dopo il decesso si toglie con il passaggio (il registro la ricorda).
                // Decisione 494 (2): anche le scelte sugli interessi degli eredi cambiate per questa successione.
                $s->forceFill(['annullato_il' => now(), 'annullato_da' => $utente->id, 'nota_annullamento' => $nota, 'registro' => $registro, 'interessi_del_defunto' => null,
                    'interessi_eredi' => null])->save();
            }

            return ['righe' => $righe, 'rate' => count($rateToccate), 'saldi' => $saldi->count(), 'arretrato' => count($padre->saldiDellArretrato()), 'avvisi' => $avvisi, 'uscente' => $uscente, 'entrante' => $entrante,
                'successione' => $padre->successione(), 'legate' => $legateInParole, 'interessi' => $interessi, 'rimessi' => $rimessi];
        });
    }

    /**
     * Chi la vendita ha tolto dalle persone del condominio (la casella «Togli … dal condominio», scritta nel registro del passaggio come
     * `tolti_dal_condominio`) e oggi non c'è: chi, senza scrivere niente. Chi è già stato rimesso dalla rubrica non si conta.
     *
     * @return Collection<int, Anagrafica>
     */
    private static function daRimettere(Subentro $padre): Collection
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($padre->registro['tolti_dal_condominio'] ?? []))));
        if ($ids === []) {
            return collect();
        }
        $presenti = DB::table('anagrafica_condominio')->where('condominio_id', $padre->condominio_id)->whereIn('anagrafica_id', $ids)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all();

        return Anagrafica::whereIn('id', array_diff($ids, $presenti))->orderBy('nome')->get();
    }

    /** Li rimette fra le persone del condominio, con il registro delle modifiche; i nomi per la frase, o null. @return array{testo: string, plurale: bool}|null */
    private static function rimetti(Subentro $padre): ?array
    {
        $persone = self::daRimettere($padre);
        foreach ($persone as $persona) {
            RegistroModifiche::legame($persona, 'condomini', 'Condomini', fn () => $persona->condomini()->syncWithoutDetaching([(int) $padre->condominio_id]));
        }

        return self::nomiDeiRimessi($persone);
    }

    /** @param Collection<int, Anagrafica> $persone @return array{testo: string, plurale: bool}|null */
    private static function nomiDeiRimessi(Collection $persone): ?array
    {
        if ($persone->isEmpty()) {
            return null;
        }
        $nomi = $persone->pluck('nome')->map(fn ($n) => (string) $n)->all();
        $ultimo = array_pop($nomi);

        return ['testo' => $nomi === [] ? $ultimo : implode(', ', $nomi) . ' e ' . $ultimo, 'plurale' => $persone->count() > 1];
    }

    // --- Le regole -----------------------------------------------------------------------------------

    /** Il passaggio e le sue pertinenze: la stessa operazione. @return Collection<int, Subentro> */
    private function famiglia(Subentro $padre): Collection
    {
        $figli = Subentro::conAnnullati()->with('immobile:id,nome')->where('subentro_padre_id', $padre->id)->orderBy('id')->get();

        return collect([$padre->loadMissing('immobile:id,nome')])->merge($figli);
    }

    /** Le unità della famiglia. @return list<int> */
    private function unita(Collection $famiglia): array
    {
        return QuoteNateDopoIlPassaggio::unita($famiglia);
    }

    /** Il nome di una parte, dal registro se la persona non c'è più. */
    private function nome(Subentro $s, string $parte): ?string
    {
        $persona = $parte === 'uscente' ? $s->uscente()->first(['id', 'nome']) : $s->entrante()->first(['id', 'nome']);

        return $persona?->nome ?? ($s->registro['nomi'][$parte] ?? null);
    }

    /**
     * Un altro passaggio registrato dopo questo, su una delle sue unità? Lo nomina come lo storico (tipo e parti) e dice da quale
     * storico si annulla: il passaggio di una pertinenza registrato con un'altra unità sta nello storico di quell'unità
     * (nella scheda della pertinenza compaiono solo i suoi passaggi padre); il passaggio suo di una pertinenza della
     * famiglia sta nello storico della pertinenza, non in quello dell'unità da cui si sta annullando. Gli incisi stanno
     * accanto a «un altro passaggio», che è maschile: «la vendita…, registrato» non concorda (giro di verifica, G-6).
     *
     * Decisione 83.1 (1.11.0-beta.50): «l'ultimo passaggio» della decisione 27 è l'ultimo registrato, non l'ultimo per data. Un passaggio
     * registrato dopo, con una data precedente, può aver letto ciò che questo ha scritto (la coppia di una fine «Scrivi» divisa sulla
     * titolarità di dopo la vendita, le fonti di una successione, una fermata): annullare prima questo lascerebbe soldi sbagliati. Un
     * passaggio legge solo ciò che è registrato prima di lui, e quello con l'id più alto si può sempre annullare: nessuno stallo.
     *
     * Decisione 705: `$salta` sono i passaggi che non contano — quelli dello stesso insieme, che si annullano insieme: registrati dopo questo
     * (lo storico, che legge un passaggio dell'insieme come se quelli dopo fossero già annullati), o tutti (l'insieme intero, che si ferma solo per un
     * passaggio registrato dopo di lui).
     *
     * @param list<int> $salta
     */
    private function passaggioDopo(Collection $famiglia, array $salta = []): ?string
    {
        $qui = (int) $famiglia->first()->immobile_id;
        foreach ($famiglia as $s) {
            $ultimo = Subentro::with(['uscente:id,nome', 'entrante:id,nome', 'padre.immobile:id,nome'])->where('immobile_id', $s->immobile_id)
                ->when($salta !== [], fn ($q) => $q->whereNotIn('id', $salta))
                ->orderByDesc('id')->first();
            if ($ultimo === null || (int) $ultimo->id <= (int) $s->id) {
                continue;
            }
            $unita = $s->immobile?->nome ?? 'l\'unità';
            $stessoGiorno = $ultimo->decorrenza?->toDateString() === $s->decorrenza?->toDateString();
            $prima = ! $stessoGiorno && $ultimo->decorrenza !== null && $s->decorrenza !== null && $ultimo->decorrenza->lt($s->decorrenza);
            $insieme = $ultimo->subentro_padre_id !== null && $ultimo->padre !== null && (int) $ultimo->padre->immobile_id !== (int) $s->immobile_id
                ? ($ultimo->padre->immobile?->nome ?? 'un\'altra unità') : null;
            $inciso = match (true) {
                $stessoGiorno && $insieme !== null => ' con la stessa data, registrato dopo, insieme ' . FrasiObbligati::a($insieme),
                $stessoGiorno => ' con la stessa data, registrato dopo',
                $prima && $insieme !== null => ', registrato dopo, con una data precedente, insieme ' . FrasiObbligati::a($insieme),
                $prima => ', registrato dopo, con una data precedente',
                $insieme !== null => ', registrato insieme ' . FrasiObbligati::a($insieme),
                default => '',
            };
            $via = match (true) {
                $insieme !== null => "Si annulla dallo storico di {$insieme}, poi questo.",
                (int) $s->immobile_id !== $qui => "Si annulla prima quello, dallo storico di {$unita}, poi questo.",
                default => 'Si annulla prima quello, poi questo.',
            };

            return sprintf('Dopo questo, su %s, c\'è un altro passaggio%s: %s, dal %s. %s', $unita, $inciso, self::descrivi($ultimo), $this->data($ultimo->decorrenza), $via);
        }

        return null;
    }

    /**
     * Rilievo X3 del terzo giro: i piani che un membro della famiglia ha preso nel conguaglio e che devono ancora seguire un
     * passaggio — il passaggio della .41 preso solo in parte (V3), o un piano preso che segue un altro passaggio. Per loro la strada
     * breve della decisione 50 («il passaggio può restare, il piano si emette così com'è») è falsa: non si emettono senza ricalcolo.
     *
     * @return list<string> i nomi dei piani
     */
    private function presiDaSeguire(Collection $famiglia): array
    {
        $pianoIds = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->whereIn('rate_quote.immobile_id', $famiglia->pluck('immobile_id')->unique()->all())->distinct()->pluck('rate.piano_rate_id');

        return PianoRate::whereIn('id', $pianoIds)->get()
            ->filter(fn (PianoRate $p) => $famiglia->contains(fn (Subentro $s) => $p->presoNelConguaglioDa($s)) && $p->passaggiDaSeguire() !== [])
            ->pluck('nome')->values()->all();
    }

    /**
     * Il passaggio in parole, come lo legge lo storico: «la vendita da Ugo a Elsa», «la fine locazione di Luca». Anche per il
     * rifiuto dell'estinzione (rilievo V8).
     */
    public static function descrivi(Subentro $s): string
    {
        $uscente = $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? null);
        $entrante = $s->entrante?->nome ?? ($s->registro['nomi']['entrante'] ?? null);

        return match ($s->tipo_passaggio) {
            // Testi T8 della beta.38: «o donazione», come il tipo di base; la donazione della nuda proprietà è il caso più frequente.
            // Rilievo W4 del giro sulle correzioni della .42: la vendita della sola nuda proprietà si dice, perché due vendite dello
            // stesso giorno fra le stesse persone (la piena e la nuda) non si confondano. La piena resta «la vendita».
            'vendita' => ($s->riservaUsufrutto() ? 'la vendita o donazione con riserva d\'usufrutto' : ($s->tipologia === 'nuda_proprietario' ? 'la vendita della nuda proprietà' : 'la vendita')) . ($uscente !== null ? " da {$uscente}" : '') . ($entrante !== null ? ' ' . FrasiObbligati::a($entrante) : ''),
            'inizio_locazione' => 'l\'inizio locazione' . ($entrante !== null ? ' ' . FrasiObbligati::a($entrante) : ''),
            'fine_locazione' => 'la fine locazione' . ($uscente !== null ? " di {$uscente}" : '') . ($entrante !== null ? ", con {$entrante} al suo posto" : ''),
            'usufrutto' => $s->tipologia === 'proprietario'
                ? 'l\'estinzione dell\'usufrutto' . ($uscente !== null ? " di {$uscente}" : '') . ($s->conAccrescimento() ? ', con l\'accrescimento' : '')
                : 'la costituzione dell\'usufrutto' . ($entrante !== null ? " a favore di {$entrante}" : ''),
            // 1.11.0-beta.44: chi entra è uno solo degli eredi (decisione 65), e non si nomina.
            'successione' => 'la successione' . ($s->tipologia === 'nuda_proprietario' ? ' nella nuda proprietà' : '') . ($uscente !== null ? " di {$uscente}" : '') . ($s->legato() ? ', per legato' : ''),
            default => 'un passaggio',
        };
    }

    /** Chi il passaggio ha fatto entrare, su tutta la famiglia (vedi `QuoteNateDopoIlPassaggio::entrati`). @return list<int> */
    private function entrati(Collection $famiglia): array
    {
        return QuoteNateDopoIlPassaggio::entrati($famiglia);
    }

    /**
     * Le quote del registro che esistono ancora e sono ancora quelle del passaggio, riconosciute dalla traccia che il passaggio ha
     * scritto dentro ciascuna e non solo dall'id (vedi `QuoteNateDopoIlPassaggio::quoteDelRegistro`).
     *
     * @return array{voci: Collection<int, object>, gemelle: Collection<int, object>} per id
     */
    private function quoteDelRegistro(Subentro $padre): array
    {
        return QuoteNateDopoIlPassaggio::quoteDelRegistro($padre);
    }

    /** `regole_calcolo` di una quota letta con il query builder, come array. */
    private function regole(object $q): array
    {
        return QuoteNateDopoIlPassaggio::regole($q);
    }

    /**
     * Le quote nate dopo il passaggio sulle sue unità che l'annullamento cambierebbe: un piano generato o ricalcolato dopo, con la
     * titolarità di dopo. La regola, con i suoi casi, sta in `QuoteNateDopoIlPassaggio`: dalla beta.51 la legge anche
     * `PianoRate::passaggiAnnullatiDaSeguire`, e le due non possono divergere.
     */
    private function quoteNateDopo(Subentro $padre, Collection $famiglia): Collection
    {
        return QuoteNateDopoIlPassaggio::per($padre, $famiglia);
    }

    private function quoteNonIntatte(Subentro $padre, Collection $famiglia): ?string
    {
        $voci = collect($padre->registro['quote'] ?? []);
        ['voci' => $vociTrovate, 'gemelle' => $gemelleTrovate] = $this->quoteDelRegistro($padre);
        $gemelle = $gemelleTrovate->keys()->map(fn ($id) => (int) $id)->all();
        $delRegistro = $vociTrovate->values()->concat($gemelleTrovate->values());
        $toccate = $delRegistro->concat($this->quoteNateDopo($padre, $famiglia));
        $uscenteId = (int) $padre->anagrafica_uscente_id;
        $entranteId = (int) $padre->anagrafica_entrante_id;
        $entrati = $this->entrati($famiglia);
        $nomi = DB::table('anagrafiche')->whereIn('id', $toccate->pluck('anagrafica_id')->push($uscenteId, $entranteId)->unique())->pluck('nome', 'id');

        // Emesse: tutte, piano per piano, e la via intera. `EmissioneRateController::destroy` rifiuta l'annullamento
        // dell'emissione per un pagamento qualunque sulla rata, di chiunque sia, o un credito usato o rimborsato (si guarda
        // tutta la rata, come fa lei). Con pagamenti la via è quella intera, con i suoi costi detti (Vincenzo, 29/09/2026:
        // giro di verifica, G-5). Il consiglio «annulla prima il conguaglio», per la guardia della beta.31, non c'è più
        // (rilievo W8 del giro sulle correzioni della .42): una rata emessa dopo il passaggio non la blocca il passaggio stesso
        // (si guarda l'ora della scrittura), la guardia vale solo per i passaggi registrati prima della .42 (decisione 49), e
        // annullare il conguaglio non riapre niente (decisione 43).
        $emesse = $toccate->filter(fn ($q) => $q->scrittura_contabile_id !== null);
        if ($emesse->isNotEmpty()) {
            $chi = $emesse->first(fn ($q) => in_array((int) $q->anagrafica_id, $entrati, true) && ! in_array((int) $q->id, $gemelle, true))
                ?? $emesse->first(fn ($q) => ! in_array((int) $q->id, $gemelle, true)) ?? $emesse->first();
            $elenco = $emesse->groupBy('piano_nome')->map(function (Collection $q, string $piano) {
                $numeri = $q->pluck('numero_rata')->map(fn ($n) => (int) $n)->unique()->sort()->values()->all();

                return sprintf('%s %s del piano «%s»', count($numeri) === 1 ? 'la rata' : 'le rate', $this->elenco($numeri), $piano);
            })->values()->all();
            $una = $emesse->pluck('rata_id')->unique()->count() === 1;
            $conPagamenti = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
                ->whereIn('rate_quote.rata_id', $emesse->pluck('rata_id')->unique()->all())->where('rate_quote.importo_pagato', '!=', 0)
                ->pluck('rate.numero_rata')->map(fn ($n) => (int) $n)->unique()->sort()->values()->all();
            $chiNome = $nomi[$chi->anagrafica_id] ?? 'chi è entrato';
            // «ad» davanti ai nomi che cominciano per A, in ogni passaggio, come in `descrivi`.
            $aChi = FrasiObbligati::a($chiNome);

            if ($conPagamenti === []) {
                return sprintf('Dopo il passaggio %s %s %s. Annulla l\'emissione di %s, poi torna qui: le rate già in mano ai condòmini non si spostano da sole.',
                    $una ? 'è stata emessa' : 'sono state emesse', $aChi, $this->elenco($elenco), $una ? 'quella rata' : 'quelle rate');
            }

            $pagate = count($conPagamenti) === 1;

            return sprintf('Dopo il passaggio %s %s %s, e %s %s ci sono già dei pagamenti. L\'emissione di una rata non si annulla finché su una qualunque delle sue quote c\'è un pagamento, di chiunque sia, o un credito già usato o rimborsato. Per annullare il passaggio andrebbero stornati prima tutti gli incassi di %s, anche quelli degli altri condòmini, e poi annullata l\'emissione; dopo, %s e gli incassi si registrano di nuovo. Ogni storno resta nel giornale e toglie l\'incasso intero, anche sulle altre rate che pagava.',
                $una ? 'è stata emessa' : 'sono state emesse', $aChi, $this->elenco($elenco),
                $pagate ? 'sulla rata' : 'sulle rate', $this->elenco($conPagamenti),
                $pagate ? 'quella rata' : 'quelle rate', $pagate ? 'la rata si emette di nuovo' : 'le rate si emettono di nuovo');
        }
        if ($q = $toccate->first(fn ($q) => (int) $q->importo_pagato !== 0)) {
            return sprintf('Sulla rata %d del piano «%s» c\'è già un pagamento di %s. Storna prima l\'incasso, poi torna qui.', $q->numero_rata, $q->piano_nome, $nomi[$q->anagrafica_id] ?? 'chi è entrato');
        }

        // Un pagamento segnalato dal portale e non verificato, sulle persone di cui l'annullamento riscrive il
        // promemoria: chi esce e chi entra sulle rate del registro (`seguonoLeQuote` riscrive lo stato di entrambi, e
        // lo stato nuovo cancellerebbe il «segnalato»), e chi ha una quota nata dopo. R17 della beta.34 legge solo chi
        // esce, perché il passaggio riscrive solo quello; la segnalazione di un altro condòmino sulla stessa rata non
        // c'entra.
        $coppie = [];
        foreach ($delRegistro as $q) {
            foreach ([$uscenteId, $entranteId, (int) $q->anagrafica_id] as $persona) {
                $coppie[$q->rata_id . ':' . $persona] = $q;
            }
        }
        foreach ($toccate as $q) {
            $coppie[$q->rata_id . ':' . $q->anagrafica_id] = $q;
        }
        $persone = collect(array_keys($coppie))->map(fn ($k) => (int) explode(':', $k)[1])->filter()->unique()->values()->all();
        if ($persone !== []) {
            $segnalata = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->status', 'reported')
                ->whereHas('anagrafiche', fn ($w) => $w->whereIn('anagrafica_id', $persone))
                ->with('anagrafiche:id,nome')->get()
                ->flatMap(fn (Evento $e) => $e->anagrafiche->map(fn ($a) => ['chiave' => ((int) ($e->meta['context']['rata_id'] ?? 0)) . ':' . $a->id, 'nome' => $a->nome]))
                ->first(fn (array $x) => isset($coppie[$x['chiave']]));
            if ($segnalata !== null) {
                $q = $coppie[$segnalata['chiave']];

                return sprintf('Sulla rata %d del piano «%s» c\'è un pagamento segnalato dal portale da %s, non ancora verificato. Verificalo o rifiutalo, poi torna qui.', $q->numero_rata, $q->piano_nome, $segnalata['nome']);
            }
        }

        // Una bozza cambiata dopo il passaggio (nome o importo) non si rimette «come prima» senza perdere la modifica. Una
        // bozza che non esiste più se n'è andata col suo piano (ricalcolato o eliminato): non c'è niente da perdere.
        foreach ($voci as $voce) {
            $q = $vociTrovate->get((int) $voce['id']);
            if ($q === null) {
                continue;
            }
            if ((int) $q->anagrafica_id !== (int) $voce['dopo']['anagrafica_id'] || (int) $q->importo !== (int) $voce['dopo']['importo']) {
                return sprintf('Una quota passata con questo passaggio (rata %d del piano «%s») è stata modificata dopo. L\'annullamento la rimetterebbe com\'era prima del passaggio, perdendo la modifica: controllala, poi correggi a mano.', $q->numero_rata, $q->piano_nome);
            }
        }

        return null;
    }

    /**
     * Una riga scritta dal passaggio e corretta a mano dopo. Con `$dopo` (decisione 705) la riga si confronta con come la lascerebbe l'annullamento dei
     * passaggi dello stesso insieme registrati dopo ({@see righeDopoGliAltri()}): la riga di chi entra, sommata dal secondo, torna quella del primo.
     *
     * @param Collection<int, Subentro>|null $dopo
     */
    private function righeCorrette(Collection $famiglia, ?Collection $dopo = null): ?string
    {
        $simulate = $dopo === null || $dopo->isEmpty() ? null : $this->righeDopoGliAltri($this->unita($famiglia), $dopo);
        foreach ($famiglia as $s) {
            foreach ($s->registro['righe'] ?? [] as $op) {
                if (! isset($op['dopo'])) {
                    continue;
                }
                $riga = $simulate !== null ? $simulate->get((int) $op['id']) : DB::table('anagrafica_immobile')->where('id', $op['id'])->first();
                $diversa = $riga === null || collect($op['dopo'])->contains(fn ($valore, $campo) => $this->normalizza($campo, $riga->{$campo} ?? null) !== $this->normalizza($campo, $valore));
                if ($diversa) {
                    $nome = $riga !== null ? DB::table('anagrafiche')->where('id', $riga->anagrafica_id)->value('nome') : null;

                    return sprintf('La riga di %s su %s è stata modificata dopo il passaggio. L\'annullamento la rimetterebbe com\'era prima del passaggio, perdendo la correzione: riportala com\'era, oppure correggi a mano anche il resto da «Modifica associazione».',
                        $nome ?? 'una persona', $s->immobile?->nome ?? 'l\'unità');
                }
            }
        }

        return null;
    }

    /**
     * Lo stato che l'annullamento lascerebbe, passato per le stesse guardie di «Associa» e della registrazione (inv. 11 e
     * 12, `GuardieTitolarita`). Una riga associata o ritoccata a mano dopo il passaggio sta fuori dal registro: resta
     * dov'è, mentre la riga di chi era uscito si riapre (Fase 1-bis, A1: la vendita di una parte, con chi vende
     * riassociato per il resto; la fine locazione con il nuovo inquilino associato a mano). Togliere righe fa solo
     * scendere le somme: basta controllare le righe che l'annullamento rimette.
     *
     * Con `$dopo` (decisione 705) si parte dalle righe come le lascerebbe l'annullamento dei passaggi dello stesso insieme registrati dopo.
     *
     * @param Collection<int, Subentro>|null $dopo
     */
    private function righeInConflitto(Collection $famiglia, ?Collection $dopo = null): ?string
    {
        foreach ($famiglia as $s) {
            $ops = collect($s->registro['righe'] ?? []);
            $aperte = $ops->where('operazione', 'aperta')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $righe = ($dopo === null || $dopo->isEmpty() ? DB::table('anagrafica_immobile')->where('immobile_id', $s->immobile_id)->get()->keyBy('id')
                : $this->righeDopoGliAltri([(int) $s->immobile_id], $dopo))
                ->reject(fn ($r) => in_array((int) $r->id, $aperte, true))->keyBy('id');
            $rimesse = [];
            foreach ($ops as $op) {
                $riga = $righe->get($op['id']);
                if ($riga === null || $op['operazione'] === 'aperta') {
                    continue;
                }
                foreach ($op['prima'] as $campo => $valore) {
                    $riga->{$campo} = $valore;
                }
                $rimesse[] = (int) $op['id'];
            }

            foreach (array_unique($rimesse) as $id) {
                $r = $righe->get($id);
                $nuova = ['anagrafica_id' => (int) $r->anagrafica_id, 'tipologia' => (string) $r->tipologia, 'quota' => (float) $r->quota, 'data_inizio' => $r->data_inizio, 'data_fine' => $r->data_fine];
                if (isset($r->attivo) && ! (bool) $r->attivo) {
                    continue;
                }
                if ($altra = GuardieTitolarita::sovrapposizioneStessaPersona($righe, $nuova, $id)) {
                    return $this->fraseConflitto($s, $r, $altra, null);
                }
                if ($sforo = GuardieTitolarita::sforoQuotePerGiorno($righe, $nuova, $id)) {
                    $inCorso = fn ($x) => (int) $x->id !== $id && (string) $x->tipologia === (string) $r->tipologia && (! isset($x->attivo) || (bool) $x->attivo)
                        && substr((string) ($x->data_inizio ?? '1900-01-01'), 0, 10) <= $sforo['giorno']
                        && ($x->data_fine === null || substr((string) $x->data_fine, 0, 10) >= $sforo['giorno']);
                    $altra = $righe->first(fn ($x) => $inCorso($x) && ! in_array((int) $x->id, $rimesse, true)) ?? $righe->first($inCorso);

                    return $this->fraseConflitto($s, $r, $altra, $sforo);
                }
            }
        }

        return null;
    }

    /**
     * Decisione 705: le righe di titolarità delle unità come le lascerebbe l'annullamento dei passaggi `$dopo` (dello stesso insieme, registrati dopo
     * quello che si legge), dall'ultimo: le righe che hanno aperto se ne vanno, quelle che hanno chiuso o cambiato tornano come prima. È ciò che trova
     * l'azione, che li annulla prima di lui. Senza scrivere niente.
     *
     * @param list<int> $unita
     * @param Collection<int, Subentro> $dopo
     * @return Collection<int, object> per id
     */
    private function righeDopoGliAltri(array $unita, Collection $dopo): Collection
    {
        $righe = DB::table('anagrafica_immobile')->whereIn('immobile_id', $unita)->get()->keyBy('id');
        foreach ($dopo->sortByDesc('id') as $membro) {
            foreach ($this->famiglia($membro) as $s) {
                foreach (array_reverse($s->registro['righe'] ?? []) as $op) {
                    $riga = $righe->get((int) $op['id']);
                    if ($riga === null) {
                        continue;
                    }
                    if ($op['operazione'] === 'aperta') {
                        $righe->forget((int) $op['id']);

                        continue;
                    }
                    foreach ((array) ($op['prima'] ?? []) as $campo => $valore) {
                        $riga->{$campo} = $valore;
                    }
                }
            }
        }

        return $righe;
    }

    private function fraseConflitto(Subentro $s, object $rimessa, ?object $altra, ?array $sforo): string
    {
        $nomi = DB::table('anagrafiche')->whereIn('id', array_filter([(int) $rimessa->anagrafica_id, (int) ($altra->anagrafica_id ?? 0)]))->pluck('nome', 'id');
        $ruolo = fn (object $r) => mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $r->tipologia)?->label() ?? (string) $r->tipologia);
        $periodo = function (object $r): string {
            $dal = $r->data_inizio ? 'dal ' . $this->data(CarbonImmutable::parse(substr((string) $r->data_inizio, 0, 10))) : 'da sempre';

            return $r->data_fine ? $dal . ' al ' . $this->data(CarbonImmutable::parse(substr((string) $r->data_fine, 0, 10))) : $dal . ', in corso';
        };
        $frase = sprintf('Annullando, su %s, %s tornerebbe %s %s', $s->immobile?->nome ?? 'l\'unità', $nomi[$rimessa->anagrafica_id] ?? 'chi era uscito', $ruolo($rimessa), $periodo($rimessa));
        if ($altra !== null) {
            $frase .= sprintf('; ma c\'è anche la riga di %s come %s %s', $nomi[$altra->anagrafica_id] ?? 'un\'altra persona', $ruolo($altra), $periodo($altra));
        }
        $frase .= $sforo === null
            ? ': i due periodi si sovrapporrebbero.'
            : sprintf(': il %s le quote farebbero %s.', $this->data(CarbonImmutable::parse($sforo['giorno'])), rtrim(rtrim(number_format($sforo['somma'], 2, ',', '.'), '0'), ','));

        return $frase . ($altra !== null ? $this->viaDelConflitto($s, $altra) : ' Se quella riga l\'hai associata dopo il passaggio, toglila con «Dissocia»; se c\'era già e l\'hai cambiata, riportala com\'era da «Modifica associazione». Poi annulla.');
    }

    /**
     * Che cosa fare della riga in conflitto, detto per il suo caso (giro di verifica, G-3). «Chiudila» non sblocca mai
     * (la riga che si riapre è in corso), e «Dissocia» rifiuta una riga con una storia — chiusa, o seguito della riga
     * chiusa dal passaggio — mentre cancella senza chiedere una riga vecchia che l'amministratore ha solo ritoccato.
     * Associata dopo o c'era già: si decide per id (`registro.riga_max_id`), come per le quote, non per data. Il vicolo
     * della continuazione (chi vende una parte, riassociato a mano per il resto dal giorno del rogito) si esce a mano:
     * Vincenzo, 29/09/2026, solo testo.
     */
    private function viaDelConflitto(Subentro $s, object $altra): string
    {
        $rigaMax = $s->registro['riga_max_id'] ?? null;
        if ($rigaMax === null) {
            return ' Se quella riga l\'hai associata dopo il passaggio, toglila con «Dissocia»; se c\'era già e l\'hai cambiata, riportala com\'era da «Modifica associazione». Poi annulla.';
        }
        if ((int) $altra->id <= (int) $rigaMax) {
            return ' Quella riga c\'era già prima del passaggio: se l\'hai cambiata dopo, riportala com\'era da «Modifica associazione», poi annulla. Non toglierla con «Dissocia»: cancelleresti anche il periodo che aveva prima.';
        }
        $riga = TitolaritaImmobile::find($altra->id);
        if ($riga !== null && $riga->eContinuazione()) {
            $giorno = $this->data(CarbonImmutable::parse(substr((string) $altra->data_inizio, 0, 10))->addDay());

            return sprintf(' Quella riga è stata associata dopo il passaggio, dal giorno del passaggio: «Dissocia» la considera il seguito della riga chiusa dal passaggio e non la cancella. Da «Modifica associazione» spostane l\'inizio al %s%s, poi toglila con «Dissocia» e annulla.',
                $giorno, $altra->data_fine !== null ? ' e togli la data di fine' : '');
        }
        if ($altra->data_fine !== null) {
            return ' Quella riga è stata associata dopo il passaggio: da «Modifica associazione» togli la data di fine, perché «Dissocia» non cancella una riga chiusa, poi toglila con «Dissocia» e annulla.';
        }

        return ' Quella riga è stata associata dopo il passaggio: toglila con «Dissocia», poi annulla.';
    }

    private function normalizza(string $campo, mixed $valore): mixed
    {
        return match ($campo) {
            'quota' => $valore === null ? null : round((float) $valore, 2),
            'data_inizio', 'data_fine' => $valore === null ? null : substr((string) $valore, 0, 10),
            'anagrafica_id' => $valore === null ? null : (int) $valore,
            default => $valore,
        };
    }

    /** «5», «5 e 6», «5, 6 e 7». @param list<int|string> $voci */
    private function elenco(array $voci): string
    {
        if (count($voci) <= 1) {
            return (string) ($voci[0] ?? '');
        }
        $ultima = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultima;
    }

    private function data(?CarbonInterface $data): string
    {
        return $data ? $data->locale('it')->translatedFormat('j F Y') : '';
    }
}
