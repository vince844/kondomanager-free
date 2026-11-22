<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\StatoAssemblea;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Gestionale\RegistroInvio;
use Illuminate\Support\Collection;

/**
 * L'elenco della convocazione di un'assemblea come lo legge la scheda e come lo usano i gesti: **chi è già fissato** (`assemblee_convocati`, all'invio di
 * ognuno, con i dati di allora) e **chi non lo è ancora** (calcolato oggi da {@see ConvocatiDellAssemblea}), ognuno con il suo mezzo e i suoi invii del
 * registro degli invii (tipo `convocazione`, con l'assemblea come documento).
 *
 * Una persona ha **un invio che vale** quando il suo ultimo invio con un mezzo dell'art. 66 è partito (non «non partito», non rimasto «in coda») e non è
 * tornato «non consegnato»: allora non si rifà. Altrimenti si può spedire di nuovo, anche con un altro mezzo.
 *
 * **Spedito** ({@see spedito()}) è la regola sola del compito «Spedire la convocazione entro il …», del passo «Convocazione» e dello stato dell'assemblea: un
 * invio che vale e che non è solo stampato. La lettera stampata non è spedita: si spedisce quando la raccomandata (o il fax) è registrata spedita, o la
 * consegna a mano è registrata.
 *
 * **Una lettera solo stampata non fissa la persona**: finché non è spedita, la voce prende i dati e il mezzo di oggi (il nome, l'indirizzo, le unità, i punti),
 * e la ristampa rifà la lettera con quelli. È **da ristampare** quando l'avviso di oggi non è più quello stampato: l'impronta dell'avviso intero (le date, il
 * luogo o la piattaforma, il tipo, il perimetro, i punti con le loro note, la persona, il mezzo) non è più quella della riga
 * ({@see EmissioneDelleConvocazioni::improntaDellAvviso()}). Chi non è più fra i convocati ha la sua lettera stampata «non più convocato», e non si spedisce.
 */
final class ElencoDeiConvocati
{
    public function __construct(private readonly ConvocatiDellAssemblea $convocati) {}

    /**
     * @return list<array<string, mixed>> ogni voce: i dati del convocato (`anagrafica_id`, `nome`, `indirizzo`, `pec`, `unita`, `punti`, `ruolo`,
     *     `unita_parole`, `quota`), `fissato` (la riga di `assemblee_convocati`, o null), `mezzo` ({@see MezzoDiConvocazione::per()}), `invio` (l'ultimo,
     *     o null), `invii` (tutti), `vale` (ha un invio che vale), `oggi` (è fra i convocati calcolati oggi), `spedito` ({@see spedito()}), `da_ristampare`
     *     (la lettera stampata non dice più l'avviso di oggi), `non_piu_convocato` (ha una lettera solo stampata e non è più fra i convocati)
     */
    public function per(Assemblea $assemblea): array
    {
        $fissati = ConvocatoDellAssemblea::query()->where('assemblea_id', $assemblea->id)->get()->keyBy(fn ($c) => (int) $c->anagrafica_id);
        $aperta = in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true);
        $calcolati = $aperta ? collect($this->convocati->alGiorno($assemblea, DateHelper::oggiUtente()))->keyBy('anagrafica_id') : collect();
        $invii = $this->invii($assemblea)->groupBy(fn (RegistroInvio $r) => (int) $r->anagrafica_id);
        // Solo raccomandata e PEC per chi riceve il punto della destinazione d'uso (art. 1117-ter): il conduttore convocato per un altro punto no.
        $dellaDestinazione = ConvocatiDellAssemblea::puntiDellaDestinazioneDUso($assemblea);

        $ids = $fissati->keys()->merge($calcolati->keys())->unique()->values();
        $elenco = [];
        $copia = null;
        foreach ($ids as $id) {
            /** @var ?ConvocatoDellAssemblea $f */
            $f = $fissati->get($id);
            $c = $calcolati->get($id);
            $suoi = ($invii->get($id) ?? collect())->values();
            $ultimo = $suoi->last();
            // Una PEC rimasta «in coda» (il lavoro si è fermato durante il collegamento) non vale: non si sa se il gestore l'ha presa, e si può rimandare.
            $vale = $ultimo !== null && ! in_array($ultimo->stato, [RegistroInvio::STATO_NON_PARTITA, RegistroInvio::STATO_IN_CODA], true)
                && $ultimo->esito !== RegistroInvio::ESITO_NON_CONSEGNATA;
            // Fissato è chi ha un invio spedito: i suoi dati restano quelli di allora. Una lettera solo stampata non fissa: dati e mezzo di oggi, che la
            // ristampa riscrive (con la lettera di prima, il conduttore convocato per un punto nuovo non lo troverebbe mai).
            $spedita = $vale && $ultimo->stato !== RegistroInvio::STATO_STAMPATA;
            $dati = $f !== null && ($spedita || $c === null) ? [
                'chiave' => 'persona-' . $id,
                'anagrafica_id' => (int) $id,
                'nome' => (string) $f->nome,
                'indirizzo' => (string) $f->indirizzo,
                'pec' => $f->pec,
                'email' => $c['email'] ?? null,
                'unita' => (array) $f->unita,
                'punti' => $f->punti === null || $f->punti === [] ? null : array_map('intval', (array) $f->punti),
                'ruolo' => ConvocatiDellAssemblea::ruoloDiChi((array) $f->unita, $f->punti === null || $f->punti === [] ? null : array_map('intval', (array) $f->punti)),
                'unita_parole' => ConvocatiDellAssemblea::unitaInParole((array) $f->unita),
                'quota' => ConvocatiDellAssemblea::quotaInParole((array) $f->unita),
            ] : $c;
            $soloRaccomandataEPec = ConvocatiDellAssemblea::riceveLaDestinazioneDUso($dati['punti'] ?? null, $dellaDestinazione);
            // Il mezzo: quello dell'invio spedito; altrimenti quello di oggi (la persona si può spedire di nuovo, o ristampare, con i suoi dati di oggi).
            $mezzo = $spedita && $f !== null
                ? ['mezzo' => $f->mezzo?->value, 'proposto' => false, 'valido' => true, 'perche' => null,
                    'pec_non_comunicata' => $f->mezzo?->value === 'pec' && $c !== null && ! filled($c['pec_comunicata_il'] ?? null)]
                : MezzoDiConvocazione::per($c ?? ['pec' => $f?->pec, 'indirizzo' => $f?->indirizzo, 'mezzo_convocazione' => $f?->mezzo?->value],
                    $soloRaccomandataEPec);
            $voce = $dati + ['fissato' => $f, 'mezzo' => $mezzo, 'invio' => $ultimo, 'invii' => $suoi->all(), 'vale' => $vale, 'oggi' => $c !== null];
            $voce['spedito'] = self::spedito($voce);
            // Una lettera stampata e non spedita è ancora quella di oggi se il suo avviso è l'avviso di oggi (per chi è convocato oggi), con il mezzo di oggi.
            // La copia dell'assemblea si legge una volta per elenco: l'avviso carica le sedute e i punti, e la copia non lascia relazioni vecchie su quella di
            // chi chiama.
            $soloStampata = $vale && ! $voce['spedito'] && $ultimo->stato === RegistroInvio::STATO_STAMPATA;
            $voce['da_ristampare'] = $soloStampata && $c !== null
                && self::cambiata($copia ??= Assemblea::query()->findOrFail($assemblea->id), $c, $mezzo['mezzo'], $ultimo);
            $voce['non_piu_convocato'] = $soloStampata && $c === null && $aperta;
            $elenco[] = $voce;
        }
        usort($elenco, fn ($x, $y) => [mb_strtolower($x['nome']), $x['anagrafica_id']] <=> [mb_strtolower($y['nome']), $y['anagrafica_id']]);

        return $elenco;
    }

    /**
     * La lettera stampata non è più quella di oggi: l'impronta dell'avviso di oggi (con il mezzo di oggi) non è quella della riga. Le righe stampate prima
     * dell'impronta si confrontano sulle date e sui punti, come allora.
     *
     * @param array<string, mixed> $oggi il convocato calcolato oggi
     */
    private static function cambiata(Assemblea $assemblea, array $oggi, ?string $mezzo, RegistroInvio $stampata): bool
    {
        $dati = (array) $stampata->dati;
        if (isset($dati['impronta_avviso'])) {
            return EmissioneDelleConvocazioni::improntaDellAvviso(EmissioneDelleConvocazioni::avviso($assemblea, $oggi, $mezzo)) !== $dati['impronta_avviso'];
        }

        return EmissioneDelleConvocazioni::dateEPunti($assemblea, $oggi, (string) ($dati['mezzo'] ?? $mezzo))
            !== EmissioneDelleConvocazioni::dateEPuntiDellInvio($dati);
    }

    /**
     * Spedito: un invio che vale e che non è solo stampato (la raccomandata o il fax non ancora registrati spediti, la lettera da consegnare a mano non ancora
     * registrata). La PEC partita è spedita; la consegna a mano registrata è spedita anche se la persona l'ha rifiutata.
     *
     * @param array<string, mixed> $voce una voce di {@see per()}
     */
    public static function spedito(array $voce): bool
    {
        return (bool) $voce['vale'] && $voce['invio']?->stato !== RegistroInvio::STATO_STAMPATA;
    }

    /**
     * Le lettere da stampare: chi si convoca oggi su carta, con un mezzo valido, e non ha ancora una lettera che vale, o ne ha una stampata con le date o i
     * punti di prima (si ristampa). `$anagrafiche` restringe.
     *
     * @param ?list<int> $anagrafiche
     * @return list<array<string, mixed>>
     */
    public function daStampare(Assemblea $assemblea, ?array $anagrafiche = null): array
    {
        return array_values(array_filter($this->per($assemblea), fn ($v) => self::daStampareLaVoce($v)
            && ($anagrafiche === null || in_array((int) $v['anagrafica_id'], $anagrafiche, true))));
    }

    /** @param array<string, mixed> $v una voce di {@see per()} */
    public static function daStampareLaVoce(array $v): bool
    {
        return $v['oggi'] && $v['mezzo']['valido'] && in_array($v['mezzo']['mezzo'], EmissioneDelleConvocazioni::MEZZI_DI_CARTA, true)
            && (! $v['vale'] || $v['da_ristampare']);
    }

    /**
     * «Hai stampato le lettere il 26/11: ristampale, perché l'avviso è cambiato.»: il giorno dell'ultima stampa di una lettera di prima, o null. La dicono la
     * scheda della convocazione, la voce del passo e il messaggio dopo ogni salvataggio che cambia l'avviso ({@see fraseDopoIlSalvataggio()}).
     *
     * @param list<array<string, mixed>> $voci
     */
    public static function fraseDellaRistampa(array $voci): ?string
    {
        $ultima = collect($voci)->filter(fn ($v) => $v['da_ristampare'])->map(fn ($v) => $v['invio']->emesso_il)->max();
        if ($ultima === null) {
            return null;
        }

        return 'Hai stampato le lettere ' . ConsegneDellaConvocazione::ilGiorno($ultima->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m'))
            . ': ristampale, perché l\'avviso è cambiato.';
    }

    /**
     * Il messaggio dopo «Salva le date», il salvataggio dell'assemblea o un punto: quello di sempre e, se ora ci sono lettere da ristampare, la frase della
     * ristampa, la stessa della scheda.
     */
    public function fraseDopoIlSalvataggio(Assemblea $assemblea, string $messaggio): string
    {
        if (! in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true) || $assemblea->eAMano()) {
            return $messaggio;
        }
        $ristampa = self::fraseDellaRistampa($this->per($assemblea));

        return $ristampa === null ? $messaggio : $messaggio . ' ' . $ristampa;
    }

    /**
     * Le persone da spedire con i mezzi dati: senza un invio che vale (o con una lettera stampata da ristampare) e con un mezzo valido fra quelli;
     * `$anagrafiche` restringe (una persona che non è fra queste non c'è).
     *
     * @param list<string> $mezzi
     * @param ?list<int> $anagrafiche
     * @return list<array<string, mixed>>
     */
    public function daSpedire(Assemblea $assemblea, array $mezzi, ?array $anagrafiche = null): array
    {
        // Una lettera stampata da ristampare non vale più: se oggi la persona si convoca per PEC (comunicata dopo la stampa), la PEC prende il suo posto.
        return array_values(array_filter($this->per($assemblea), fn ($v) => (! $v['vale'] || $v['da_ristampare']) && $v['oggi'] && $v['mezzo']['valido']
            && in_array($v['mezzo']['mezzo'], $mezzi, true) && ($anagrafiche === null || in_array((int) $v['anagrafica_id'], $anagrafiche, true))));
    }

    /** Gli invii della convocazione, dal primo. @return Collection<int, RegistroInvio> */
    public function invii(Assemblea $assemblea): Collection
    {
        return RegistroInvio::query()->where('documentabile_type', $assemblea->getMorphClass())->where('documentabile_id', $assemblea->id)
            ->where('tipo', RegistroInvio::TIPO_CONVOCAZIONE)->orderBy('id')->get();
    }
}
