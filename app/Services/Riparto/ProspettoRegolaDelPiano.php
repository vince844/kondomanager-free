<?php

namespace App\Services\Riparto;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\CalcoloQuoteService;
use App\Services\Subentro\ChiPagaAlPostoDellInquilino;
use App\Services\Subentro\FermataDopoScrivi;
use App\Services\Subentro\RegolaDelPiano;
use App\Support\InsiemePeriodi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Coda 245 (1.11.0-beta.55): il prospetto degli oneri accessori sull'anello di una fine con la regola del piano (la strada «P» di
 * `ProspettoOneriAccessori`).
 *
 * Dove la locazione è continuata con un coinquilino e la fine ha la regola del piano nel registro (`conguaglio.piano`), la parte «Inquilino» di
 * ogni voce dell'anello si divide con la regola del piano e con la titolarità di oggi: a ogni conduttore la sua parte, con i suoi giorni, e accanto
 * ciò che ne ha nelle sue rate (le righe congelate dal piano). Ciò che i passaggi sull'anello hanno scritto in saldi è «con il conguaglio» di quel
 * passaggio; ciò che hanno lasciato fra le parti lo dice il passaggio che l'ha lasciato (decisione 111.3, la differenza del piano del passaggio): con
 * «Non scriverlo» chi esce e chi riceveva i suoi giorni, nel resto di una fine le persone del resto, nell'inizio il conduttore e chi pagherebbe di
 * meno. Le cifre di chi rimborsa chi sono quelle che il registro del passaggio conserva: le stesse che il pannello ha detto. I giorni senza
 * conduttore sono la parte di chi sta al posto dell'inquilino; quelli che non copre nessuno restano a chi è uscito, e gli si restituiscono fra le
 * parti, come nella fine senza coinquilini.
 *
 * Una voce che nessun passaggio sull'anello ha cambiato resta com'è, congelata dal piano: ridividerla sposterebbe un centesimo dove nessuno cambia.
 * Il già versato «dell'unità» è già tolto dall'anello (`RegolaDelPiano::anelli`); quello «della persona» resta di chi l'ha versato, e si dice accanto.
 */
final class ProspettoRegolaDelPiano
{
    public function __construct(
        private readonly ChiPagaAlPostoDellInquilino $chiPaga = new ChiPagaAlPostoDellInquilino(),
        private readonly RegolaDelPiano $regola = new RegolaDelPiano(),
    ) {
    }

    /**
     * I passaggi sull'anello della fine, nell'ordine in cui sono stati registrati: la fine stessa e i passaggi dopo che la seguono sullo stesso gruppo
     * (unità e gestione): le fini e i cambi d'inquilino con la loro voce di `conguaglio.piano`, gli inizi con i giorni del conduttore della regola del
     * piano, le fini senza coppia con il loro resto (chi esce non ha quote del piano, o chi riceve i suoi giorni non ne ha, o pagherebbe di più come lui).
     *
     * @param list<int> $pianoIds
     * @return list<array{s: Subentro, tipo: string, voce: array<string, mixed>}>
     */
    public function passaggi(Subentro $fine, int $immobileId, int $gestioneId, array $pianoIds): array
    {
        $out = [];
        $suoi = fn (array $g) => (int) ($g['immobile_id'] ?? 0) === $immobileId && (int) ($g['gestione_id'] ?? 0) === $gestioneId;
        foreach (Subentro::where('condominio_id', $fine->condominio_id)->whereNull('subentro_padre_id')->where('id', '>=', $fine->id)->orderBy('id')->get() as $s) {
            if ($s->tipo_passaggio === 'inizio_locazione') {
                foreach ($s->registro['giorni_del_conduttore'] ?? [] as $g) {
                    if (($g['regola'] ?? null) === 'piano' && $suoi($g) && (int) ($g['fine_id'] ?? 0) === (int) $fine->id) {
                        $out[] = ['s' => $s, 'tipo' => 'inizio', 'voce' => $g];
                    }
                }
                continue;
            }
            if ($s->tipo_passaggio !== 'fine_locazione') {
                continue;
            }
            $voce = collect($s->registro['conguaglio']['piano'] ?? [])->first(fn ($g) => $suoi($g)
                && array_intersect(array_map(fn ($v) => (int) ($v['piano_rate_id'] ?? 0), (array) ($g['voci'] ?? [])), $pianoIds) !== []);
            if ($voce !== null && ((int) $s->id === (int) $fine->id || (int) ($voce['segue_fine_id'] ?? 0) === (int) $fine->id)) {
                $out[] = ['s' => $s, 'tipo' => $s->anagrafica_entrante_id !== null ? 'cambio' : 'fine', 'voce' => $voce];
                continue;
            }
            // Un passaggio dopo senza coppia non ha la scelta, e il registro non ha la sua regola del piano: ne conserva il resto.
            $resto = collect($s->registro['resto_del_piano'] ?? [])->first(fn ($r) => $suoi($r) && (int) ($r['fine_id'] ?? 0) === (int) $fine->id);
            if ($resto !== null) {
                $out[] = ['s' => $s, 'tipo' => 'senza_coppia', 'voce' => $resto];
            }
        }

        return $out;
    }

    /**
     * Le voci della regola del piano di un piano sull'unità: per ogni voce dell'anello la parte di ogni persona con la titolarità di oggi (`importo`),
     * con i suoi giorni di conduzione e ciò che ne ha nelle sue rate (`nelle_rate`). Le persone con una riga d'inquilino sull'unità sono conduttori;
     * le altre stanno al posto dell'inquilino nei giorni senza conduttore, e la loro parte è una voce sola per voce dell'anello. La parte dei giorni che
     * non copre nessuno è nelle rate di chi è uscito: una voce dei giorni senza conduttore, da restituirgli fra le parti.
     *
     * @param list<array{s: Subentro, tipo: string, voce: array<string, mixed>}> $passaggi i passaggi sull'anello (`passaggi`)
     * @param Collection<int, array<string, mixed>> $riparto le righe del riparto dell'unità (`DettaglioRiparto`)
     * @param \Closure(array<string, mixed>, ?InsiemePeriodi): array<string, mixed> $base i campi di una voce del prospetto da una riga del riparto
     * @param list<int> $conduttori chi ha una riga d'inquilino sull'unità
     * @param list<Subentro> $cambi i cambi d'inquilino della .50 che prima della fine hanno già spostato l'anello (`RegolaDelPiano::cambiCheHannoSpostatoLAnello`)
     * @return array{conduttori: array<int, list<array<string, mixed>>>, senza: list<array<string, mixed>>, nessuno: array<int, int>, paganti: list<int>}|array{ferma: string}
     */
    public function voci(PianoRate $piano, int $immobileId, array $passaggi, Collection $riparto, \Closure $base, array $conduttori, array $cambi = []): array
    {
        $anelli = $this->regola->anelli($piano, $immobileId);
        // Decisione 115.1: un piano senza il riparto registrato si legge con l'anello delle quote pure degli inquilini; se la parte dell'inquilino non si separa
        // più (un inquilino ha preso dopo la fine un altro ruolo sull'unità), la regola del piano non si ricalcola, e il prospetto lascia fuori il piano.
        if (isset($anelli['ferma'])) {
            return ['ferma' => (string) $anelli['perche']];
        }
        $righe = $this->chiPaga->righe($immobileId);
        $ultimo = end($passaggi) ?: null;
        // A pari resto il centesimo a chi è uscito con l'ultimo passaggio sull'anello, come nella sua coppia; l'inizio di un conduttore non ha chi esce.
        $primo = $ultimo === null || $ultimo['tipo'] === 'inizio' ? null : ((int) ($ultimo['voce']['uscente_id'] ?? $ultimo['voce']['persona_id'] ?? $ultimo['s']->anagrafica_uscente_id) ?: null);
        $chiEsce = (int) ($passaggi[0]['s']->anagrafica_uscente_id ?? 0);
        $nomi = [];
        $nome = function (int $id) use (&$nomi): string {
            return $nomi[$id] ??= (string) Anagrafica::whereKey($id)->value('nome');
        };
        $fine = $passaggi[0]['s'];
        $dellUscita = ['strada' => 'P', 'scelta' => $fine->registro['conguaglio']['scelta'] ?? null, 'uscita_del' => $fine->decorrenza->toDateString()];
        $out = ['conduttori' => [], 'senza' => [], 'nessuno' => [], 'paganti' => []];
        foreach ($anelli as $v) {
            $r = $this->rigaDellaVoce($riparto, $v);
            $riga = $r === null ? ['conto_id' => $v['conto_id'], 'conto_nome' => $v['conto'], 'tabella_id' => 0, 'tabella_nome' => null, 'valore_millesimo' => 0, 'somma_valori' => 0, 'tabella_quota' => null] : $r;
            $campi = $base($riga, $v['competenza']);
            if ($r === null || $this->piuTabelle($riparto, $v)) {
                $campi = array_replace($campi, ['tabella_id' => 0, 'tabella' => null, 'millesimi' => null]);
            }
            if ((int) $v['conto_id'] === 0) {
                // Il piano senza il riparto registrato ha un anello solo, la somma delle quote pure degli inquilini sulle rate: le voci non si distinguono.
                $campi = array_replace($campi, ['conto' => 'Voci a carico dell\'inquilino', 'quota_inquilino' => null,
                    'nota' => 'il piano non ha il riparto registrato: le voci a carico dell\'inquilino si leggono insieme, dalle quote delle rate']);
            }
            $pesi = $this->chiPaga->pesiDelPiano($righe, $v['competenza']);
            [$oggi, $nessuno] = $this->cambiata($v, $passaggi, $cambi) ? RegolaDelPiano::partiConINessuno((int) $v['anello'], $pesi['pesi'], $primo) : [$v['prima'], 0];
            $senza = ['importo' => 0, 'nelle_rate' => 0, 'chi' => []];
            foreach (array_keys($oggi + $v['prima']) as $id) {
                $parte = (int) ($oggi[$id] ?? 0);
                $rate = (int) ($v['prima'][$id] ?? 0);
                if ($rate !== 0) {
                    $out['paganti'][] = (int) $id;
                }
                if (in_array((int) $id, $conduttori, true)) {
                    $out['conduttori'][(int) $id][] = $campi + $dellUscita + ['importo' => $parte, 'nelle_rate' => $rate, 'giorni' => isset($pesi['giorni'][$id]) ? (int) $pesi['giorni'][$id] : null,
                        'tratti' => null, 'modo' => 'piano', 'pagato_da' => null];
                    continue;
                }
                $senza['importo'] += $parte;
                $senza['nelle_rate'] += $rate;
                if ($parte !== 0) {
                    $senza['chi'][] = $nome((int) $id);
                }
            }
            $diNessuno = $nessuno !== 0 ? (int) ($pesi['di_nessuno']?->giorni() ?? 0) : 0;
            // Chi sta al posto dell'inquilino ha righe dell'anello nelle sue rate solo se alla generazione c'erano giorni che nessun inquilino copriva (le righe d'inquilino
            // chiuse prima, o la regola D7 che copre i buchi prima di una riga): le conta accanto alla sua parte, come un conduttore.
            if ($senza['importo'] !== 0) {
                $out['senza'][] = $campi + $dellUscita + ['importo' => $senza['importo'], 'nelle_rate' => $senza['nelle_rate'],
                    'giorni' => $pesi['scoperti'] !== null ? $pesi['scoperti']->giorni() - $diNessuno : null, 'tratti' => null, 'modo' => 'piano', 'pagato_da' => null,
                    'chi' => implode(', ', array_unique($senza['chi'])) ?: null];
            }
            if ($nessuno !== 0) {
                // I giorni che non copre nessuno, neanche chi sta al posto dell'inquilino: la loro parte è nelle rate di chi esce, come nella fine senza coinquilini.
                $di = $primo ?? $chiEsce;
                $out['senza'][] = $campi + $dellUscita + ['importo' => $nessuno, 'giorni' => $diNessuno, 'tratti' => null, 'modo' => 'da_restituire', 'pagato_da' => $nome($di)];
                $out['nessuno'][$di] = ($out['nessuno'][$di] ?? 0) + $nessuno;
            }
        }
        foreach ($this->versatoDellaPersona($piano, $immobileId, $anelli, $base) as $id => $voci) {
            if (in_array((int) $id, $conduttori, true)) {
                array_push($out['conduttori'][(int) $id], ...$voci);
            }
        }
        $out['paganti'] = array_values(array_unique($out['paganti']));

        return $out;
    }

    /**
     * Che cosa hanno fatto i passaggi sull'anello, persona per persona: i conguagli scritti in saldi (`conguagli`), quelli annullati, che le parti hanno
     * regolato fra loro (`regolati`), e chi rimborsa chi per ciò che i passaggi hanno lasciato fra le parti (`rimborsi`, con il verso: «a» la persona
     * rimborsa, «da» le si restituisce). Le cifre sono quelle del registro di ogni passaggio. Due voci fra le stesse due persone si sommano.
     *
     * Chi riceve la parte di un altro e la sua riga gli è arrivata con un passaggio che si è fermato (la vendita del proprietario sul ripiego dopo la
     * fine) non ha pagato quei giorni: li ha pagati chi è rimasto a carico della fermata, o lui se le parti l'hanno regolata (Coda 352).
     *
     * I cambi d'inquilino della .50 che prima della fine hanno già spostato l'anello (`$cambi`, `RegolaDelPiano::cambiCheHannoSpostatoLAnello`): la fine parte da ciò
     * che hanno scritto, e il loro conguaglio è «con il conguaglio del cambio d'inquilino del …» di ognuno, dalle righe in saldi; con «Non scriverlo» chi entra lo
     * rimborsa a chi esce («il cambio d'inquilino del … non ha scritto il conguaglio»).
     *
     * @param list<array{s: Subentro, tipo: string, voce: array<string, mixed>}> $passaggi
     * @param array<int, int> $nessuno la parte dei giorni che non copre nessuno, per chi l'ha nelle sue rate (`voci`)
     * @param list<int> $paganti chi ha righe dell'anello nelle sue rate
     * @param list<Subentro> $cambi
     * @return array<int, array{conguagli: list<array<string, mixed>>, regolati: list<array<string, mixed>>, rimborsi: list<array<string, mixed>>}>
     */
    public function composizione(array $passaggi, int $immobileId, int $gestioneId, array $nessuno, array $paganti, array $cambi = []): array
    {
        $persone = [];
        $vuota = ['conguagli' => [], 'regolati' => [], 'rimborsi' => []];
        $coppie = [];
        foreach ($cambi as $c) {
            if ($c->conguaglioNonScritto()) {
                // Con «Non scriverlo» la coppia del cambio è rimasta fra le parti: chi entra la rimborsa a chi esce (`regolato_fuori`, per questa unità).
                array_push($coppie, ...self::coppie(RegolaDelPiano::regolatiDelCambio($c, $immobileId, $gestioneId),
                    sprintf('il cambio d\'inquilino del %s non ha scritto il conguaglio', $c->decorrenza->format('d/m/Y'))));
                continue;
            }
            // Con il conguaglio annullato le parti l'hanno regolato fra loro: le persone di `regolato_fuori` sulla gestione, per questa unità.
            $righe = $c->conguaglioAnnullato()
                ? collect(RegolaDelPiano::regolatiDelCambio($c, $immobileId, $gestioneId))
                : Saldo::where('subentro_id', (int) $c->id)->where('immobile_id', $immobileId)->where('gestione_id', $gestioneId)->get()
                    ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'));
            foreach ($righe->filter(fn (int $x) => $x !== 0) as $id => $importo) {
                $persone[(int) $id] ??= $vuota;
                $persone[(int) $id][$c->conguaglioAnnullato() ? 'regolati' : 'conguagli'][] = ['del' => $c->decorrenza->toDateString(), 'tipo' => 'cambio', 'importo' => $importo];
            }
        }
        foreach ($passaggi as $x) {
            $s = $x['s'];
            $del = $s->decorrenza->toDateString();
            $data = CarbonImmutable::parse($del)->format('d/m/Y');
            if ($x['tipo'] === 'inizio') {
                $parti = collect($x['voce']['parti'] ?? [])->mapWithKeys(fn ($p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all();
                array_push($coppie, ...self::coppie($parti, sprintf('con l\'inizio della locazione di %s del %s', (string) ($x['voce']['conduttore'] ?? $s->entrante?->nome ?? '?'), $data)));
                continue;
            }
            if ($x['tipo'] === 'senza_coppia') {
                $parti = collect($x['voce']['parti'] ?? [])->mapWithKeys(fn ($p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all();
                // Chi esce senza righe dell'anello nelle sue rate non aveva quote del piano; chi le aveva non ha scritto righe perché chi riceve i suoi giorni non
                // ha quote, o pagherebbe di più come lui.
                array_push($coppie, ...self::coppie($parti, sprintf(in_array((int) ($x['voce']['persona_id'] ?? 0), $paganti, true)
                    ? 'con la fine della locazione di %s del %s, che nei saldi non ha scritto niente' : 'con la fine della locazione di %s del %s, che non aveva quote del piano',
                    (string) ($x['voce']['persona'] ?? '?'), $data)));
                continue;
            }
            $parti = array_map('intval', (array) ($x['voce']['parti'] ?? []));
            $resto = array_map('intval', (array) ($x['voce']['resto'] ?? []));
            $passaggio = $x['tipo'] === 'cambio' ? sprintf('il cambio d\'inquilino del %s', $data) : sprintf('la fine della locazione del %s', $data);
            if (! isset($s->registro['conguaglio']['scelta'])) {
                // La prima fine senza una coppia (nessuna scelta): nei saldi non ha scritto niente, e il resto si regola fra le parti.
                array_push($coppie, ...self::coppie($resto, $passaggio . ', che nei saldi non ha scritto niente'));
                continue;
            }
            if (($s->registro['conguaglio']['scelta'] ?? null) !== Subentro::CONGUAGLIO_SCRITTO) {
                array_push($coppie, ...self::coppie($parti, $passaggio . ' non ha scritto il conguaglio'), ...self::coppie($resto, $passaggio . ' non ha scritto il conguaglio'));
                continue;
            }
            $dove = $s->conguaglioAnnullato() ? 'regolati' : 'conguagli';
            foreach ($parti as $id => $c) {
                $persone[(int) $id] ??= $vuota;
                $persone[(int) $id][$dove][] = ['del' => $del, 'tipo' => $x['tipo'], 'importo' => $c];
            }
            array_push($coppie, ...self::coppie($resto, 'la parte che ' . $passaggio . ' ha lasciato fuori dal conguaglio'));
        }
        $coppie = $this->chiHaPagato($coppie, $passaggi[0]['s'], $immobileId, $gestioneId, array_keys($persone), $paganti);
        foreach ($nessuno as $id => $c) {
            $coppie[] = ['da' => null, 'a' => (int) $id, 'importo' => $c, 'perche' => 'i giorni che non copre nessuno, neanche chi sta al posto dell\'inquilino'];
        }

        // Fra le stesse due persone una voce sola: la somma, con il verso che ne viene.
        $perCoppia = [];
        foreach ($coppie as $c) {
            [$x, $y, $segno] = $c['da'] === null || ($c['a'] !== null && $c['da'] > $c['a']) ? [$c['a'], $c['da'], -1] : [$c['da'], $c['a'], 1];
            $k = $x . '|' . $y;
            $perCoppia[$k] ??= ['x' => $x, 'y' => $y, 'importo' => 0, 'perche' => [], 'oppure' => []];
            $perCoppia[$k]['importo'] += $segno * (int) $c['importo'];
            $perCoppia[$k]['perche'][] = $c['perche'];
            if (! empty($c['oppure'])) {
                $perCoppia[$k]['oppure'][] = $c['oppure'];
            }
        }
        $nome = fn (?int $id) => $id === null ? null : (string) Anagrafica::whereKey($id)->value('nome');
        foreach ($perCoppia as $c) {
            if ($c['importo'] === 0) {
                continue;
            }
            // `x` rimborsa `y` se l'importo è positivo; altrimenti è `y` che restituisce.
            [$paga, $riceve] = $c['importo'] > 0 ? [$c['x'], $c['y']] : [$c['y'], $c['x']];
            $voce = ['importo' => abs($c['importo']), 'perche' => implode('; ', array_values(array_unique($c['perche']))), 'oppure' => implode('; ', array_values(array_unique($c['oppure']))) ?: null];
            if ($paga !== null) {
                $persone[$paga] ??= $vuota;
                $persone[$paga]['rimborsi'][] = ['verso' => 'a', 'id' => $riceve, 'nome' => $nome($riceve)] + $voce;
            }
            if ($riceve !== null) {
                $persone[$riceve] ??= $vuota;
                $persone[$riceve]['rimborsi'][] = ['verso' => 'da', 'id' => $paga, 'nome' => $nome($paga)] + $voce;
            }
        }

        return $persone;
    }

    /**
     * La frase della fine sull'anello (la scelta, scritto o no, o il conguaglio annullato) e, se ci sono, i saldi del Wallet legati ai passaggi
     * sull'anello, con la persona e la cifra.
     *
     * @param list<array{s: Subentro, tipo: string, voce: array<string, mixed>}> $passaggi
     * @return list<string>
     */
    public function frasi(array $passaggi, int $immobileId, int $gestioneId): array
    {
        $fine = $passaggi[0]['s'];
        $data = $fine->decorrenza->format('d/m/Y');
        $con = ChiPagaAlPostoDellInquilino::coinquiliniDelPassaggio($fine, $immobileId, (int) $fine->anagrafica_uscente_id, CarbonImmutable::parse($fine->decorrenza->toDateString()));
        $con = self::elenco($con) ?: 'un coinquilino';
        $frasi = [match (true) {
            ! isset($fine->registro['conguaglio']['scelta']) => sprintf('Alla fine della locazione del %s la locazione è continuata con %s, e nei saldi non è stato scritto niente: con la regola del piano la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno; chi ha pagato più della sua parte ha la differenza «da restituire», chi meno «da rimborsare», fra le parti.', $data, $con),
            ($fine->registro['conguaglio']['scelta'] ?? null) !== Subentro::CONGUAGLIO_SCRITTO => sprintf('Alla fine della locazione del %s la locazione è continuata con %s, e il conguaglio non è stato scritto: con la regola del piano la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno; chi ha pagato più della sua parte ha la differenza «da restituire», chi meno «da rimborsare», fra le parti.', $data, $con),
            $fine->conguaglioAnnullato() => sprintf('Alla fine della locazione del %s la locazione è continuata con %s, e il conguaglio scritto con la regola del piano è stato annullato: le parti hanno regolato fra loro la differenza di quella fine («regolata fra le parti»), e la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno.', $data, $con),
            default => sprintf('Alla fine della locazione del %s la locazione è continuata con %s, e il conguaglio è stato scritto con la regola del piano: la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno, e la coppia ha portato ognuno alla sua parte («con il conguaglio della fine locazione del %s»).', $data, $con, $data),
        }];
        foreach ($passaggi as $x) {
            $righe = Saldo::where('passaggio_id', (int) $x['s']->id)->where('immobile_id', $immobileId)->where('gestione_id', $gestioneId)->get();
            $somme = $righe->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->filter(fn (int $c) => $c !== 0)->all();
            if ($somme !== []) {
                $nomi = Anagrafica::whereIn('id', array_keys($somme))->pluck('nome', 'id')->map(fn ($n) => (string) $n)->all();
                $frasi[] = sprintf('Ci sono saldi del Wallet legati %s: %s; se sono la parte che qui è «da restituire» e «da rimborsare», quella parte è già regolata.',
                    FermataDopoScrivi::al(FermataDopoScrivi::passaggioInParole($x['s'])), RegolaDelPiano::partiInParole($somme, $nomi));
            }
        }

        return $frasi;
    }

    /**
     * Chi rimborsa chi per un insieme di parti che sommano zero (positivo = deve, negativo = gli si deve): con una persona sola da una parte, ognuna
     * dall'altra parte con la sua cifra; con più persone dalle due parti, nell'ordine, finché le cifre non sono finite.
     *
     * @param array<int, int> $parti
     * @return list<array{da: int, a: int, importo: int, perche: string}>
     */
    private static function coppie(array $parti, string $perche): array
    {
        $debiti = array_filter($parti, fn (int $c) => $c > 0);
        $crediti = array_map(fn (int $c) => -$c, array_filter($parti, fn (int $c) => $c < 0));
        $out = [];
        foreach ($debiti as $da => $d) {
            foreach ($crediti as $a => $cr) {
                $quanto = min($d, $cr);
                if ($quanto <= 0) {
                    continue;
                }
                $out[] = ['da' => (int) $da, 'a' => (int) $a, 'importo' => $quanto, 'perche' => $perche];
                $d -= $quanto;
                $crediti[$a] -= $quanto;
                if ($d === 0) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Coda 352 sull'anello della regola del piano: una persona di una coppia che non ha pagato niente dell'anello (nessuna riga nelle sue rate, nessun
     * conguaglio scritto) e che sta lì perché le è arrivata la riga di chi stava al posto dell'inquilino con un passaggio che si è fermato: i suoi
     * giorni li ha pagati chi è rimasto a carico della fermata, o lei se le parti l'hanno regolata. La coppia passa a chi è rimasto a carico, con
     * l'alternativa.
     *
     * @param list<array{da: int, a: int, importo: int, perche: string}> $coppie
     * @param list<int> $conguagliati chi ha un conguaglio scritto o annullato
     * @param list<int> $paganti chi ha righe dell'anello nelle sue rate
     * @return list<array<string, mixed>>
     */
    private function chiHaPagato(array $coppie, Subentro $fine, int $immobileId, int $gestioneId, array $conguagliati, array $paganti): array
    {
        $fermate = null;
        $alPosto = function (int $id) use (&$fermate, $fine, $immobileId, $gestioneId, $conguagliati, $paganti): ?array {
            if (in_array($id, $conguagliati, true) || in_array($id, $paganti, true)) {
                return null;
            }
            $fermate ??= Subentro::where('condominio_id', $fine->condominio_id)->whereNull('subentro_padre_id')->where('id', '>', $fine->id)->orderBy('id')->get()
                ->flatMap(fn (Subentro $s) => collect($s->registro['fermata_dopo_scrivi'] ?? [])->filter(fn ($f) => (int) ($f['fine_id'] ?? 0) === (int) $fine->id
                    && (int) ($f['immobile_id'] ?? 0) === $immobileId && (int) ($f['gestione_id'] ?? 0) === $gestioneId)->map(fn ($f) => ['s' => $s, 'f' => $f]))
                ->values();
            $x = $fermate->first(fn (array $x) => collect($x['f']['parti'] ?? [])->contains(fn ($p) => (int) $p['anagrafica_id'] === $id));
            if ($x === null) {
                return null;
            }

            return ['id' => (int) $x['f']['persona_id'], 'oppure' => sprintf('%s, se le parti hanno regolato fra loro la parte del conguaglio che %s del %s non ha spostato',
                (string) Anagrafica::whereKey($id)->value('nome'), FermataDopoScrivi::passaggioInParole($x['s'], false), $x['s']->decorrenza->format('d/m/Y'))];
        };
        foreach ($coppie as &$c) {
            foreach (['da', 'a'] as $lato) {
                $chi = $alPosto((int) $c[$lato]);
                if ($chi !== null) {
                    $c[$lato] = $chi['id'];
                    $c['oppure'] = $chi['oppure'];
                }
            }
        }
        unset($c);

        return $coppie;
    }

    /**
     * La voce dell'anello è cambiata con un passaggio sull'anello? Una fine (o un cambio) la cambia se la sua fotografia ne ha cambiato le parti; un inizio
     * o la fine di chi non ha quote, che la fotografia non hanno, se cadono dentro la sua competenza.
     *
     * Un cambio d'inquilino della .50 prima della fine (`$cambi`) la cambia se cade dentro la sua competenza, come un inizio: la sua coppia ha spostato anche
     * quella voce, e la fotografia della fine non lo dice, perché parte da ciò che il cambio ha scritto (`RegolaDelPiano::dellaFine`). Una voce la cui competenza
     * finisce prima del cambio resta congelata: il cambio non l'ha toccata.
     *
     * @param array<string, mixed> $v la voce di `RegolaDelPiano::anelli`
     * @param list<array{s: Subentro, tipo: string, voce: array<string, mixed>}> $passaggi
     * @param list<Subentro> $cambi
     */
    private function cambiata(array $v, array $passaggi, array $cambi = []): bool
    {
        foreach ($cambi as $c) {
            if ($c->decorrenza->toDateString() <= $v['competenza']->al()->toDateString()) {
                return true;
            }
        }
        foreach ($passaggi as $x) {
            if (in_array($x['tipo'], ['fine', 'cambio'], true)) {
                $foto = collect($x['voce']['voci'] ?? [])->first(fn ($w) => (int) ($w['piano_rate_id'] ?? 0) === (int) $v['piano_rate_id'] && (int) ($w['conto_id'] ?? 0) === (int) $v['conto_id']
                    && ($w['competenza'] ?? null) === $v['competenza']->toArray());
                $prima = array_filter(array_map('intval', (array) ($foto['prima'] ?? [])));
                $dopo = array_filter(array_map('intval', (array) ($foto['dopo'] ?? [])));
                ksort($prima);
                ksort($dopo);
                if ($foto === null || $prima !== $dopo) {
                    return true;
                }
                continue;
            }
            if ($x['s']->decorrenza->toDateString() <= $v['competenza']->al()->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * La riga del riparto che dà alla voce del prospetto la tabella, i millesimi e la quota: la prima dell'inquilino sullo stesso conto e con la stessa
     * competenza, o sullo stesso conto.
     *
     * @param Collection<int, array<string, mixed>> $riparto
     * @param array<string, mixed> $v
     * @return ?array<string, mixed>
     */
    private function rigaDellaVoce(Collection $riparto, array $v): ?array
    {
        $delConto = $riparto->filter(fn ($r) => ($r['ruolo_richiesto'] ?? null) === 'inquilino' && (int) ($r['conto_id'] ?? 0) === (int) $v['conto_id'])->values();
        $dal = $v['competenza']->dal()->toDateString();
        $al = $v['competenza']->al()->toDateString();

        return $delConto->first(fn ($r) => substr((string) ($r['competenza_dal'] ?? ''), 0, 10) === $dal && substr((string) ($r['competenza_al'] ?? ''), 0, 10) === $al) ?? $delConto->first();
    }

    /**
     * La voce dell'anello sta su più tabelle? Allora la parte del conduttore è una per voce, e la tabella non si dice.
     *
     * @param Collection<int, array<string, mixed>> $riparto
     * @param array<string, mixed> $v
     */
    private function piuTabelle(Collection $riparto, array $v): bool
    {
        return $riparto->filter(fn ($r) => ($r['ruolo_richiesto'] ?? null) === 'inquilino' && (int) ($r['conto_id'] ?? 0) === (int) $v['conto_id'])
            ->pluck('tabella_id')->unique()->count() > 1;
    }

    /**
     * Il già versato «della persona» che tocca l'anello: resta di chi l'ha versato (le sue rate ne sono già al netto), e si dice accanto alla sua parte,
     * come nelle altre voci del prospetto. La parte «dell'unità» è già tolta dall'anello.
     *
     * @param list<array<string, mixed>> $anelli
     * @return array<int, list<array<string, mixed>>>
     */
    private function versatoDellaPersona(PianoRate $piano, int $immobileId, array $anelli, \Closure $base): array
    {
        $righe = DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('immobile_id', $immobileId)->get(['anagrafica_id', 'conto_id', 'conto_nome', 'tipo', 'ruolo_richiesto', 'importo', 'riga_descrizione']);
        $out = [];
        foreach ($righe->where('tipo', 'netting')->where('riga_descrizione', CalcoloQuoteService::NETTING_DELLA_PERSONA)->groupBy(fn ($r) => (int) $r->anagrafica_id . '|' . (int) $r->conto_id) as $chiave => $g) {
            [$id, $conto] = array_map('intval', explode('|', (string) $chiave));
            $suoi = $righe->filter(fn ($r) => (int) $r->anagrafica_id === $id && (int) $r->conto_id === $conto && $r->tipo !== 'netting');
            $nellAnello = (float) abs((int) $suoi->filter(fn ($r) => $r->tipo === 'riparto' && $r->ruolo_richiesto === 'inquilino')->sum('importo'));
            if ($nellAnello <= 0.0) {
                continue;
            }
            $parte = (int) MoneyHelper::ripartisciPerQuote((int) $g->sum('importo'), ['anello' => $nellAnello, 'altro' => (float) abs((int) $suoi->sum('importo')) - $nellAnello])['anello'];
            if ($parte === 0) {
                continue;
            }
            $out[$id][] = ['piano' => $piano->nome, 'piano_rate_id' => (int) $piano->id, 'conto_id' => $conto, 'conto' => ((string) ($g->first()->conto_nome ?? 'Voce')) . ' — già versato',
                'tabella_id' => 0, 'tabella' => null, 'millesimi' => null, 'quota_inquilino' => null, 'competenza' => null, 'nota' => null, 'importo' => $parte, 'giorni' => null,
                'tratti' => null, 'modo' => 'rate', 'pagato_da' => null];
        }

        return $out;
    }

    /** «Ines, Dora e Pia». */
    private static function elenco(array $voci): string
    {
        $voci = array_values(array_filter(array_map('strval', $voci), fn (string $v) => $v !== ''));
        if (count($voci) <= 1) {
            return $voci[0] ?? '';
        }
        $ultimo = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultimo;
    }
}
