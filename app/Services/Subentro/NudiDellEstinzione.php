<?php

namespace App\Services\Subentro;

use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Subentro;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Decisione 57 (1.11.0-beta.43, D2): quali nudi proprietari tornano pieni quando un usufrutto si estingue.
 *
 * Fino alla .42 tornavano pieni **tutti** i nudi in corso sull'unità: con due usufrutti sulle due metà, alla fine del primo
 * tornava piena anche la nuda dell'altra metà, ancora in usufrutto (la somma delle quote arrivava al 150 %), e il conguaglio
 * si divideva con chi non c'entrava. Se il nudo dell'altra metà era chi esce, chi esce diventava pieno di una metà ancora in
 * usufrutto e il conguaglio scriveva una coppia con sé stesso.
 *
 * La regola:
 * - se sull'unità non c'è un altro usufrutto in corso, tornano pieni tutti i nudi (com'era), anche chi esce quando è nudo
 *   di una parte: la sua nuda è sotto l'usufrutto che finisce;
 * - se c'è, torna piena solo la nuda dell'usufrutto che finisce. Chi esce non torna nudo di sé stesso: la nuda censita
 *   insieme al suo usufrutto, o prima, è quella dell'altra parte. Ma se chi esce ha una nuda che può essere quella della
 *   parte che finisce — comprata mentre era usufruttuario, o indicata dal registro — il programma si ferma e indica la via a
 *   mano (decisione 61), e così quando i nudi possibili valgono meno dell'usufrutto;
 * - se l'usufrutto è nato da un passaggio (costituzione o riserva), quale nuda torna piena lo dice il registro di quel
 *   passaggio, seguito attraverso le vendite della nuda e le sue somme (la nuda comprata che si somma a quella che chi compra
 *   aveva già: torna piena solo la parte che il registro conosce), e lo applica il programma. Sui titolari censiti a mano
 *   nessuna tabella lo dice: se i nudi possibili valgono più della quota dell'usufrutto, sceglie l'amministratore, senza una
 *   scelta già fatta — nudi interi che insieme valgono quanto l'usufrutto, oppure tutti, ciascuno per la sua quota (la
 *   donazione congiunta, decisione 62).
 *
 * Una nuda proprietà sola sotto due usufrutti (una riga che vale più dell'usufrutto che finisce: due genitori che donano con
 * riserva la nuda al figlio, poi ne muore uno) si **consolida per legge** solo per la parte dell'usufrutto che finisce: il
 * figlio diventa pieno per quella parte e resta nudo del resto (scelta di Vincenzo del 04/10/2026, in attesa della scelta
 * fra consolidamento e accrescimento che arriva con la successione). `consolida` dice la quota che torna piena.
 */
class NudiDellEstinzione
{
    public const DA_TUTTI = 'tutti';
    public const DA_REGISTRO = 'registro';
    public const DA_SCELTA = 'scelta';
    public const DA_CONSOLIDAMENTO = 'consolidamento';
    /** Decisione 62: tutti i nudi, ciascuno per la sua quota della parte dell'usufrutto che finisce. */
    public const DA_PER_QUOTA = 'per_quota';
    /** Decisione 61: il programma non sa quale nuda torna piena, e si ferma. */
    public const DA_FERMO = 'fermo';

    private const A_MANO = 'Registra l\'estinzione a mano: chiudi da «Modifica associazione» la riga dell\'usufrutto al giorno prima e correggi le righe dei nudi proprietari secondo l\'atto, senza conguaglio automatico.';

    /**
     * @param list<int>|null $scelti le righe dei nudi che l'amministratore ha spuntato, se il modulo le ha chieste
     * @param bool $perQuota l'amministratore ha scelto «tutti, ciascuno per la sua quota» (decisione 62)
     * @return array{nudi: Collection<int, TitolaritaImmobile>, da: string, candidati: Collection<int, TitolaritaImmobile>, serve_scelta: bool, fermo: bool, interi: bool, errore: ?string, consolida: array<int, float>}
     */
    public function per(TitolaritaImmobile $usufrutto, CarbonImmutable $decorrenza, ?array $scelti = null, bool $perQuota = false): array
    {
        $unita = (int) $usufrutto->immobile_id;
        $inCorso = fn (string $ruolo) => TitolaritaImmobile::with('anagrafica')->where('immobile_id', $unita)->where('tipologia', $ruolo)->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza))->values();
        $tutti = $inCorso('nuda_proprietario');
        $diChiEsce = fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id === (int) $usufrutto->anagrafica_id;
        // Con un altro usufrutto in corso i nudi possibili sono gli altri: la nuda di chi esce è di norma quella dell'altra parte.
        $candidati = $tutti->reject($diChiEsce)->values();
        $altriUsufrutti = $inCorso('usufruttuario')->reject(fn (TitolaritaImmobile $t) => (int) $t->id === (int) $usufrutto->id);
        $interi = true;
        $esito = function (Collection $nudi, string $da, bool $serve = false, ?string $errore = null, array $consolida = []) use ($candidati, &$interi, &$altriUsufrutti) {
            return ['nudi' => $nudi->values(), 'da' => $da, 'candidati' => $candidati, 'serve_scelta' => $serve, 'fermo' => $da === self::DA_FERMO,
                'interi' => $interi, 'errore' => $errore, 'consolida' => $consolida, 'altro_usufrutto' => ! $altriUsufrutti->isEmpty()];
        };

        if ($altriUsufrutti->isEmpty()) {
            // Senza un altro usufrutto ogni nuda in corso è sotto quello che finisce, anche quella di chi esce (la nuda comprata
            // mentre era usufruttuario): chi esce torna pieno della sua parte, e la sua parte del conguaglio resta a lui.
            return $esito($tutti, self::DA_TUTTI);
        }
        $quotaUsufrutto = round((float) $usufrutto->quota, 2);
        $nome = $usufrutto->anagrafica?->nome ?? 'chi esce';
        $registro = $this->dalRegistro($usufrutto, $tutti);
        $fermo = fn (string $frase) => $esito(collect(), self::DA_FERMO, errore: $frase . ' ' . self::A_MANO);
        // Decisione 61: il registro del passaggio da cui l'usufrutto è nato porta a una nuda di chi esce. Prima di ogni altra
        // regola: un registro che porta anche lì non decide.
        if ($registro['chi_esce'] ?? false) {
            return $fermo(sprintf('Su questa unità c\'è un altro usufrutto in corso, e il passaggio da cui è nato l\'usufrutto di %s porta a una sua nuda proprietà: il programma non sa quale nuda proprietà torna piena.', $nome));
        }
        // Il registro decide solo se le nude che indica valgono quanto l'usufrutto: un usufrutto sommato (una parte censita e
        // una riservata vendendo) ne conosce solo una parte, e allora sceglie l'amministratore. Una nuda che si è sommata a
        // un'altra della stessa persona torna piena solo per la parte che il registro conosce (decisione 62). Quando decide, una
        // nuda che chi esce ha comprato dopo è per forza dell'altra parte (rilievo G3 del giro sulle correzioni).
        if ($registro !== null && round(array_sum($registro['noti']), 2) === $quotaUsufrutto) {
            $consolida = [];
            foreach ($registro['righe'] as $t) {
                $noto = round((float) $registro['noti'][(int) $t->id], 2);
                if ($noto < round((float) $t->quota, 2)) {
                    $consolida[(int) $t->id] = $noto;
                }
            }

            return $esito($registro['righe'], self::DA_REGISTRO, consolida: $consolida);
        }
        // Decisione 61: chi esce ha una nuda nata dopo l'inizio del suo usufrutto (comprata mentre era usufruttuario), e il
        // registro non dice quale nuda torna piena. L'inizio è quello dell'usufrutto più vecchio da cui la riga viene: una riserva
        // che lo allarga riapre la riga con una data nuova, ma l'usufrutto c'era già (rilievo G1 del giro sulle correzioni).
        $inizio = $this->inizioDellUsufrutto($usufrutto);
        $nataDopo = $tutti->filter($diChiEsce)->first(fn (TitolaritaImmobile $t) => $t->data_inizio !== null
            && ($inizio === null || $t->data_inizio->gt($inizio)));
        if ($nataDopo !== null) {
            return $fermo(sprintf('Su questa unità c\'è un altro usufrutto in corso, e %s ha anche una nuda proprietà che può essere quella della parte su cui l\'usufrutto finisce: il programma non sa quale nuda proprietà torna piena.', $nome));
        }
        $somma = round((float) $candidati->sum('quota'), 2);
        if ($somma === $quotaUsufrutto) {
            return $esito($candidati, self::DA_TUTTI);
        }
        if ($somma < $quotaUsufrutto) {
            // Decisione 61: una parte della nuda dell'usufrutto che finisce non sta fra i nudi possibili (è di chi esce, o manca).
            return $fermo($candidati->isEmpty()
                ? sprintf('Su questa unità c\'è un altro usufrutto in corso, e nessun altro nudo proprietario può tornare proprietario pieno: la nuda proprietà della parte su cui l\'usufrutto di %s finisce può essere una nuda di %s, o mancare fra le righe in corso.', $nome, $nome)
                : sprintf('Su questa unità c\'è un altro usufrutto in corso, e i nudi proprietari che possono tornare proprietari pieni valgono in tutto %s, meno dell\'usufrutto di %s (%s %%): una parte della nuda proprietà non sta fra loro (può essere una nuda di %s, o mancare fra le righe in corso).',
                    self::percentuale($somma), $nome, $this->numero($quotaUsufrutto), $nome));
        }
        $unaSolaRiga = $candidati->first(fn (TitolaritaImmobile $t) => round((float) $t->quota, 2) > $quotaUsufrutto);
        if ($candidati->count() === 1 && $unaSolaRiga !== null) {
            // Consolidamento di legge: torna piena solo la parte che l'usufrutto copriva; il resto resta nuda proprietà.
            return $esito($candidati, self::DA_CONSOLIDAMENTO, consolida: [(int) $unaSolaRiga->id => $quotaUsufrutto]);
        }
        $interi = self::combinazioneEsiste($candidati->map(fn (TitolaritaImmobile $t) => (int) round((float) $t->quota * 100))->all(), (int) round($quotaUsufrutto * 100));
        if ($perQuota) {
            // Decisione 62: la donazione congiunta. Ogni nudo torna pieno per la sua quota della parte che finisce, in centesimi
            // di punto con i resti maggiori, così le parti sommano proprio all'usufrutto.
            $parti = MoneyHelper::ripartisciPerQuote((int) round($quotaUsufrutto * 100), $candidati->mapWithKeys(fn (TitolaritaImmobile $t) => [(int) $t->id => (float) $t->quota])->all());
            // Rilievo G4 del giro sulle correzioni: una parte che vale tutta la quota del nudo lo fa tornare pieno per intero (niente
            // nuda del resto a zero); una parte zero lascia la sua nuda com'è (niente piena a zero).
            $nudi = collect();
            $consolida = [];
            foreach ($candidati as $t) {
                $parte = (int) $parti[(int) $t->id];
                if ($parte <= 0) {
                    continue;
                }
                $nudi->push($t);
                if ($parte < (int) round((float) $t->quota * 100)) {
                    $consolida[(int) $t->id] = $parte / 100;
                }
            }

            return $esito($nudi, self::DA_PER_QUOTA, consolida: $consolida);
        }
        if ($scelti === null || $scelti === []) {
            return $esito(collect(), self::DA_SCELTA, true, $interi
                ? sprintf('Su questa unità c\'è un altro usufrutto in corso, e i nudi proprietari valgono più dell\'usufrutto di %s (%s %%): scegli quali tornano proprietari pieni, quelli della parte su cui l\'usufrutto finisce, oppure tutti, ciascuno per la sua quota, se la nuda proprietà è in comune fra i nudi proprietari.', $nome, $this->numero($quotaUsufrutto))
                : sprintf('Su questa unità c\'è un altro usufrutto in corso, e nessuna combinazione di nudi proprietari interi vale l\'usufrutto di %s (%s %%): se la nuda proprietà è in comune fra i nudi proprietari, scegli «tutti, ciascuno per la sua quota»; altrimenti %s', $nome, $this->numero($quotaUsufrutto), mb_lcfirst(self::A_MANO)));
        }
        $ids = array_map('intval', $scelti);
        $nudi = $candidati->filter(fn (TitolaritaImmobile $t) => in_array((int) $t->id, $ids, true))->values();
        if ($nudi->count() !== count(array_unique($ids))) {
            // Rilievi G6 e HT9: il modulo propone i nudi in corso il giorno scritto; se uno non lo è più, le righe sono cambiate dopo
            // l'apertura della pagina.
            return $esito(collect(), self::DA_SCELTA, true, sprintf('Uno dei nudi proprietari che hai indicato non è fra quelli in corso su questa unità il %s: le righe possono essere cambiate dopo l\'apertura della pagina. Ricaricala e scegli di nuovo.', $decorrenza->locale('it')->translatedFormat('j F Y')));
        }
        if (round((float) $nudi->sum('quota'), 2) !== $quotaUsufrutto) {
            return $esito(collect(), self::DA_SCELTA, true, sprintf(
                'I nudi proprietari che hai indicato valgono %s, l\'usufrutto di %s %s: scegli quelli della parte su cui l\'usufrutto finisce, che insieme valgono quanto l\'usufrutto, oppure tutti, ciascuno per la sua quota.',
                self::percentuale(round((float) $nudi->sum('quota'), 2)), $nome, self::percentuale($quotaUsufrutto)));
        }

        return $esito($nudi, self::DA_SCELTA);
    }

    /**
     * Rilievo A6 della Fase 1-bis della .43: la nuda che torna piena solo in parte (il consolidamento) è nata il giorno
     * dell'estinzione. Il programma non scrive ancora quella forma, e non si consiglia una data diversa da quella dell'atto.
     */
    public static function fraseNudaNataLoStessoGiorno(?string $nome): string
    {
        return sprintf('La nuda proprietà di %s è nata il giorno dell\'estinzione e torna piena solo in parte: il programma non registra ancora, nello stesso giorno, la nuda e il suo consolidamento. Correggi le righe a mano da «Modifica associazione», senza conguaglio automatico.', $nome ?? 'un nudo proprietario');
    }

    /**
     * Una quota con il suo articolo (rilievo GT3 del giro sulle correzioni): «il 25 %», «l'8 %», «l'11 %», «l'80 %», «lo 0,5 %».
     * L'articolo segue il suono del numero: uno, otto, undici, ottanta… cominciano per vocale, zero per «z».
     */
    public static function percentuale(float $quota): string
    {
        // Rilievo HT4: l'articolo e il numero dallo stesso valore (una somma in virgola mobile può dare 79,9999…).
        $quota = round($quota, 2);
        $intero = (int) floor($quota);
        $numero = rtrim(rtrim(number_format($quota, 2, ',', '.'), '0'), ',');
        $articolo = match (true) {
            $intero === 0 => 'lo ',
            in_array($intero, [1, 8, 11], true) || ($intero >= 80 && $intero <= 89) => 'l\'',
            default => 'il ',
        };

        return $articolo . $numero . ' %';
    }

    /**
     * Rilievo G1 del giro sulle correzioni: il giorno da cui chi esce è usufruttuario, risalendo le righe d'usufrutto che i
     * passaggi hanno chiuso aprendo quella di oggi (una riserva che allarga l'usufrutto lo somma in una riga nuova, con la data
     * della riserva). Per ogni riga si cerca il passaggio che l'ha **aperta** — non quello che la nomina per ultimo — e se ne
     * prendono le righe d'usufrutto della stessa persona chiuse insieme. Null vuol dire «da sempre» (una riga senza data).
     */
    private function inizioDellUsufrutto(TitolaritaImmobile $usufrutto): ?CarbonImmutable
    {
        if ($usufrutto->data_inizio === null) {
            return null;
        }
        $inizio = CarbonImmutable::parse($usufrutto->data_inizio);
        $passaggi = Subentro::where('immobile_id', $usufrutto->immobile_id)->get();
        $daVedere = [(int) $usufrutto->id];
        $visti = [];
        while ($daVedere !== []) {
            $id = array_pop($daVedere);
            if (isset($visti[$id])) {
                continue;
            }
            $visti[$id] = true;
            $apertura = $passaggi->first(fn (Subentro $s) => collect($s->registro['righe'] ?? [])->contains(fn ($o) => ($o['operazione'] ?? null) === 'aperta' && (int) ($o['id'] ?? 0) === $id));
            foreach (collect($apertura?->registro['righe'] ?? [])->where('operazione', 'chiusa') as $o) {
                $chiusa = TitolaritaImmobile::find((int) ($o['id'] ?? 0));
                if ($chiusa === null || $chiusa->tipologia !== 'usufruttuario' || (int) $chiusa->anagrafica_id !== (int) $usufrutto->anagrafica_id) {
                    continue;
                }
                if ($chiusa->data_inizio === null) {
                    return null;
                }
                $inizio = $inizio->min(CarbonImmutable::parse($chiusa->data_inizio));
                $daVedere[] = (int) $chiusa->id;
            }
        }

        return $inizio;
    }

    /** Se qualche insieme di quote (in centesimi di punto) vale proprio `$obiettivo`: la scelta per nudi interi è possibile. */
    private static function combinazioneEsiste(array $quote, int $obiettivo): bool
    {
        $raggiungibili = [0 => true];
        foreach ($quote as $q) {
            foreach (array_keys($raggiungibili) as $somma) {
                if ($somma + $q <= $obiettivo) {
                    $raggiungibili[$somma + $q] = true;
                }
            }
        }

        return isset($raggiungibili[$obiettivo]);
    }

    /**
     * Le righe di nuda proprietà legate all'usufrutto dal passaggio che l'ha fatto nascere (costituzione o riserva), seguite
     * attraverso le vendite della nuda e le sue somme con altra nuda della stessa persona, con la parte che il registro conosce
     * di ciascuna. Null se l'usufrutto non è nato da un passaggio, o se la catena non arriva a righe ancora in corso (una riga
     * corretta a mano, un passaggio annullato a metà). `chi_esce` se la catena arriva a una nuda di chi esce.
     *
     * @param Collection<int, TitolaritaImmobile> $inCorso le nude in corso sull'unità
     * @return array{righe: Collection<int, TitolaritaImmobile>, noti: array<int, float>, chi_esce: bool}|null
     */
    private function dalRegistro(TitolaritaImmobile $usufrutto, Collection $inCorso): ?array
    {
        $origine = Subentro::origineDellUsufrutto((int) $usufrutto->id, (int) $usufrutto->immobile_id);
        if ($origine === null) {
            return null;
        }
        $operazioni = collect($origine->registro['righe'] ?? []);
        $nudaDi = fn ($r) => ($r['dopo']['tipologia'] ?? TitolaritaImmobile::find((int) ($r['id'] ?? 0))?->tipologia) === 'nuda_proprietario';
        $origini = [];
        foreach ($operazioni->filter(fn ($r) => in_array($r['operazione'] ?? null, ['aperta', 'modificata'], true))->filter($nudaDi) as $r) {
            $riga = TitolaritaImmobile::find((int) $r['id']);
            if ($riga === null) {
                return null;
            }
            // La parte di questo passaggio: la quota della riga, meno la nuda della stessa persona che vi si è sommata (chiusa nello
            // stesso passaggio, o la quota di prima di una riga cambiata sul posto).
            $sommata = $operazioni->filter(fn ($o) => ($o['operazione'] ?? null) === 'chiusa' && (int) ($o['id'] ?? 0) !== (int) $r['id'])
                ->map(fn ($o) => TitolaritaImmobile::find((int) $o['id']))->filter(fn ($t) => $t !== null && $t->tipologia === 'nuda_proprietario' && (int) $t->anagrafica_id === (int) $riga->anagrafica_id)
                ->sum(fn ($t) => (float) $t->quota);
            $quota = (float) ($r['dopo']['quota'] ?? $riga->quota) - (($r['operazione'] ?? null) === 'modificata' ? (float) ($r['prima']['quota'] ?? 0) : $sommata);
            $origini[(int) $r['id']] = ($origini[(int) $r['id']] ?? 0.0) + $quota;
        }
        if ($origini === []) {
            return null;
        }
        $dopo = $this->nudaDopo((int) $usufrutto->immobile_id);
        $noti = [];
        foreach ($origini as $id => $quota) {
            $corrente = self::seguiLaNuda($id, fn (int $x): ?int => $dopo[$x] ?? null);
            $noti[$corrente] = ($noti[$corrente] ?? 0.0) + $quota;
        }
        $righe = $inCorso->filter(fn (TitolaritaImmobile $t) => isset($noti[(int) $t->id]))->values();
        if ($righe->count() !== count($noti)) {
            return null;
        }

        return ['righe' => $righe, 'noti' => $noti, 'chi_esce' => $righe->contains(fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id === (int) $usufrutto->anagrafica_id)];
    }

    /**
     * Dove va una nuda nei passaggi dell'unità: la vendita la porta da chi vende a chi compra, e un passaggio che chiude la nuda di
     * una persona e gliene apre un'altra (chi compra che era già nudo: le due si sommano) la porta nella nuova. Lo stesso modo di
     * seguirla della genealogia della quota (`GenealogiaDellaQuota::struttura`).
     *
     * @return array<int, int> riga di nuda → la riga in cui è finita
     */
    private function nudaDopo(int $immobileId): array
    {
        $dopo = [];
        foreach (Subentro::where('immobile_id', $immobileId)->orderBy('decorrenza')->orderBy('id')->get() as $s) {
            if ($s->tipo_passaggio === 'vendita' && $s->tipologia === 'nuda_proprietario' && $s->riga_uscente_id !== null && $s->riga_entrante_id !== null) {
                $dopo[(int) $s->riga_uscente_id] ??= (int) $s->riga_entrante_id;
            }
            $ops = collect($s->registro['righe'] ?? []);
            $nuove = $ops->filter(fn ($o) => in_array($o['operazione'] ?? null, ['aperta', 'modificata'], true))
                ->map(fn ($o) => TitolaritaImmobile::find((int) $o['id']))->filter(fn ($t) => $t !== null && $t->tipologia === 'nuda_proprietario');
            foreach ($ops->where('operazione', 'chiusa') as $o) {
                $chiusa = TitolaritaImmobile::find((int) $o['id']);
                if ($chiusa === null || $chiusa->tipologia !== 'nuda_proprietario') {
                    continue;
                }
                $nuova = $nuove->first(fn ($t) => (int) $t->anagrafica_id === (int) $chiusa->anagrafica_id && (int) $t->id !== (int) $chiusa->id);
                if ($nuova !== null) {
                    $dopo[(int) $chiusa->id] ??= (int) $nuova->id;
                }
            }
        }

        return $dopo;
    }

    /**
     * La riga di nuda proprietà in cui è finita `$id`: si segue, un passaggio dopo l'altro, la riga che `$dopo` indica (di norma
     * quella di chi ha comprato la nuda), finché non ce n'è un'altra. Lo stesso ciclo per la regola dei nudi qui sopra e per la
     * genealogia della quota (`GenealogiaDellaQuota`, decisione 56), così le due non seguono la nuda in modi diversi. Una riga già
     * vista ferma il ciclo: un registro corrotto non lo fa girare per sempre.
     *
     * @param \Closure(int): ?int $dopo
     */
    public static function seguiLaNuda(int $id, \Closure $dopo): int
    {
        $visti = [];
        while (! isset($visti[$id])) {
            $visti[$id] = true;
            $prossima = $dopo($id);
            if ($prossima === null) {
                break;
            }
            $id = $prossima;
        }

        return $id;
    }

    private function numero(float $quota): string
    {
        return rtrim(rtrim(number_format($quota, 2, ',', '.'), '0'), ',');
    }
}
