<?php

namespace App\Services\Import\Livelli;

use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\ImportBatchItem;
use App\Models\Saldo;
use App\Services\Import\Canonical\CanonicalSaldiApertura;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Livello 10 — i **saldi di apertura**. Il ponte fra il vecchio gestionale e il nostro (§2).
 *
 * È l'unico dato importato con conseguenze legali: da qui nascono morosità, solleciti e decreti
 * ingiuntivi. Un saldo sbagliato non si nota subito — si trascina in ogni riparto successivo.
 *
 * ## La quadratura è obbligatoria, e non è un avviso
 *
 * Prima di scrivere una sola riga si confronta la somma dei saldi con il **totale scritto nel
 * riparto**. Scarto diverso da zero significa che qualcosa non è stato letto, o è stato letto
 * due volte, o il file è stato modificato dopo l'esportazione: in tutti e tre i casi i saldi non
 * devono entrare, e l'errore porta lo scarto nel messaggio.
 *
 * Il concorrente su questo punto mette un servizio umano di onboarding gratuito. Noi possiamo
 * fare un controllo automatico perché il numero di verifica è **dentro la stampa** che
 * l'amministratore ha appena caricato (§17.5) — e un controllo scala, un servizio no.
 *
 * ## Dove finiscono i saldi dei titolari cessati
 *
 * Sul **saldo solidale** dell'unità (`anagrafica_id` a NULL): è la forma con cui Kondomanager
 * rappresenta un debito che segue la casa e non la persona, art. 63 disp. att. c.c. Il wallet
 * dei Saldi Iniziali la gestisce già, quindi qui non si inventa niente — si compila quello che
 * c'è.
 */
final class LivelloSaldi implements LivelloImport
{
    public const CHIAVE = 'saldi';

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Saldi di apertura';
    }

    public function dipendeDa(): array
    {
        return [LivelloCondominio::CHIAVE, LivelloEsercizi::CHIAVE, LivelloUnita::CHIAVE];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $mancanti = [];

        $esercizio = $ctx->risolto(LivelloEsercizi::CHIAVE);

        if (! $esercizio instanceof Esercizio || ! Esercizio::whereKey($esercizio->getKey())->exists()) {
            $mancanti[] = new PrerequisitoMancante(
                'saldi.esercizio_mancante',
                'I saldi di apertura non hanno un esercizio a cui appartenere.',
                'Importa prima il livello «Esercizi»: un saldo iniziale è per definizione '
                .'l\'apertura di un esercizio preciso.',
            );
        }

        if (! $ctx->haRisolto(LivelloUnita::CHIAVE)) {
            $mancanti[] = new PrerequisitoMancante(
                'saldi.unita_mancanti',
                'Le unità immobiliari non sono in archivio, e ogni saldo appartiene a un\'unità.',
                'Importa prima il livello «Unità immobiliari».',
            );
        }

        // La gestione è il «cassetto» su cui il saldo si appoggia (art. 1130-bis): senza,
        // il wallet non saprebbe a quale gestione riferire il pregresso. La crea il livello
        // «Esercizi» riusando il servizio dell'applicazione — ma si verifica **a database**,
        // non si dà per fatto.
        if ($esercizio instanceof Esercizio && $this->gestione($esercizio) === null) {
            $mancanti[] = new PrerequisitoMancante(
                'saldi.gestione_mancante',
                'L\'esercizio non ha nessuna gestione a cui agganciare i saldi.',
                'Crea una gestione ordinaria sul condominio e collegala all\'esercizio: è il '
                .'cassetto in cui vive il pregresso di ogni condòmino.',
            );
        }

        if (! $ctx->canonico(self::CHIAVE) instanceof CanonicalSaldiApertura) {
            $mancanti[] = new PrerequisitoMancante(
                'saldi.dati_assenti',
                'Nessuno dei file caricati contiene i saldi di apertura.',
                'Serve il «Consuntivo ripartizioni per unità / anagrafica» dell\'ultimo esercizio '
                .'chiuso: è la stampa che porta il saldo finale di ogni condòmino, ed è anche '
                .'quella che contiene il totale su cui verificarli.',
            );
        }

        return $mancanti;
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var CanonicalSaldiApertura $dati */
        $dati = $ctx->canonico(self::CHIAVE);
        /** @var Condominio $condominio */
        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);
        /** @var Esercizio $esercizio */
        $esercizio = $ctx->risolto(LivelloEsercizi::CHIAVE);
        $gestione = $this->gestione($esercizio);

        $unita = $ctx->risoltiMolti(LivelloUnita::CHIAVE);
        $soggetti = $ctx->risoltiMolti(LivelloSoggetti::CHIAVE);

        $rilievi = [];
        $avvisi = [];
        $daScrivere = [];

        foreach ($dati->righe as $saldo) {
            $immobile = $this->risolviUnita($saldo, $unita, $rilievi);

            if ($immobile === null) {
                continue;
            }

            $anagrafica = null;

            if (! $saldo->isSolidale()) {
                $anagrafica = $this->risolviSoggetto($saldo, $soggetti, $rilievi, $avvisi);

                if ($anagrafica === null) {
                    continue;
                }
            }

            $daScrivere[] = [$saldo, $immobile, $anagrafica];
        }

        // Un riferimento irrisolto è già un errore di riga; ma anche se lo fosse solo per una,
        // la somma non tornerebbe più e la quadratura lo direbbe comunque. Ci si ferma prima
        // perché il messaggio della riga è più utile di quello dello scarto.
        if ($rilievi !== []) {
            return EsitoCommit::bloccato(...$rilievi);
        }

        $quadratura = $this->verificaQuadratura($dati);

        if ($quadratura !== null) {
            return EsitoCommit::bloccato($quadratura);
        }

        if ($dati->totaleRiferimentoCents === null) {
            $avvisi[] = Rilievo::avviso(
                'saldi.quadratura_non_verificabile',
                'Il file non porta il totale, quindi non ho potuto verificare da solo che i saldi tornino.',
                'Confrontali con l\'ultimo rendiconto approvato prima di emettere il primo piano rate: '
                .'un saldo sbagliato si trascina in tutti i riparti successivi.',
            );
        }

        $daScrivere = $this->accorpaSolidali($daScrivere);

        $creati = 0;
        $saltati = 0;
        /** @var list<string> $incoerenti */
        $incoerenti = [];

        foreach ($daScrivere as [$saldo, $immobile, $anagrafica]) {
            $esistente = Saldo::where('esercizio_id', $esercizio->id)
                ->where('immobile_id', $immobile->getKey())
                ->where('gestione_id', $gestione?->id)
                ->where('anagrafica_id', $anagrafica?->getKey())
                ->first();

            if ($esistente !== null) {
                $saltati++;

                continue;
            }

            $riga = Saldo::create([
                'esercizio_id' => $esercizio->id,
                'condominio_id' => $condominio->id,
                'gestione_id' => $gestione?->id,
                'immobile_id' => $immobile->getKey(),
                'anagrafica_id' => $anagrafica?->getKey(),
                'saldo_iniziale' => $saldo->importoCents,
                'origine' => 'importato',
                'descrizione' => match (true) {
                    $saldo->daTitolareCessato => 'Saldo di un titolare non più attuale, importato sull\'unità (art. 63 disp. att. c.c.)',
                    $saldo->daNomeDiviso => 'Posizione di un nome doppio diviso in due persone: resta in solido sull\'unità',
                    default => null,
                },
            ]);

            $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $riga, $saldo->rigaSorgente);
            $creati++;

            // Il riparto e l'elenco unità sono due stampe distinte, e nessuno garantisce che
            // raccontino lo stesso momento: il caso vero è il riparto del consuntivo chiuso più
            // l'elenco di oggi, con in mezzo una compravendita. Il saldo finisce allora su una
            // persona che su quell'unità non è più (o non è ancora) titolare.
            //
            // Non è un errore — l'art. 63 disp. att. c.c. rende comunque l'unità il riferimento,
            // e il dato è quello che i file dicono. Ma è **l'unica cosa che nessuno dei due file
            // può dichiarare da solo**, e va detta: incrociarli è il motivo per cui li chiediamo
            // insieme.
            if ($anagrafica !== null && ! $this->haTitolarita($anagrafica, $immobile)) {
                $incoerenti[] = sprintf('%s su %s', $anagrafica->nome, $immobile->descrizione ?? $immobile->nome);
            }
        }

        $divisi = array_filter($daScrivere, fn (array $v) => $v[0]->daNomeDiviso);

        if ($divisi !== []) {
            $avvisi[] = Rilievo::avviso(
                'saldi.da_nome_diviso',
                sprintf(
                    '%d %s di un nome doppio che hai diviso: %s in solido sull\'unità.',
                    count($divisi),
                    count($divisi) === 1 ? 'posizione era' : 'posizioni erano',
                    count($divisi) === 1 ? 'resta' : 'restano',
                ),
                'Il riparto intesta quella posizione a entrambe le persone insieme e non dice in '
                .'che proporzione dividerla: spezzarla a metà sarebbe inventare una ripartizione '
                .'su denaro altrui. Se sai come va divisa, falla tu dopo l\'importazione.',
            );
        }

        if ($incoerenti !== []) {
            $avvisi[] = Rilievo::avviso(
                'saldi.titolarita_non_corrispondente',
                sprintf(
                    '%d %s a una persona che su quell\'unità non risulta titolare: %s.',
                    count($incoerenti),
                    count($incoerenti) === 1 ? 'saldo è intestato' : 'saldi sono intestati',
                    implode('; ', array_slice($incoerenti, 0, 5)).(count($incoerenti) > 5 ? '; …' : ''),
                ),
                'Di solito significa che il riparto e l\'elenco unità sono di due momenti diversi, '
                .'con una compravendita in mezzo. Il saldo è entrato lo stesso e resta legato '
                .'all\'unità: controlla chi deve pagarlo prima di emettere il primo piano rate.',
            );
        }

        return new EsitoCommit(creati: $creati, saltati: $saltati, avvisi: $avvisi);
    }

    /**
     * Un'unità ha **un solo** saldo solidale per esercizio e gestione.
     *
     * Lo dice l'architettura dei Saldi Iniziali (`docs/architettura_saldi_iniziali.md` §2: «al
     * massimo un saldo solidale per (esercizio, gestione, immobile)»), e lo dice il dominio: il
     * saldo solidale è il debito che segue **la casa**, quindi ce n'è uno per casa — non uno
     * per ciascuno dei titolari che si sono succeduti.
     *
     * Un'unità che ha cambiato proprietario e inquilino nello stesso esercizio produce due
     * righe `ex …` nel riparto, ed è il caso normale, non un caso limite. La prima stesura di
     * questo livello le scriveva come due saldi: il secondo veniva poi scartato dal controllo
     * anti-duplicato, **il suo importo spariva e la quadratura falliva** — cioè il difetto si
     * annunciava da solo, ma solo grazie alla quadratura. Senza quel controllo, sarebbe finito
     * in archivio un pregresso più leggero del vero, senza che niente lo segnalasse.
     *
     * @param  list<array{0: CanonicalSaldo, 1: Model, 2: Model|null}>  $daScrivere
     * @return list<array{0: CanonicalSaldo, 1: Model, 2: Model|null}>
     */
    private function accorpaSolidali(array $daScrivere): array
    {
        $solidali = [];
        $risultato = [];

        foreach ($daScrivere as $voce) {
            [$saldo, $immobile, $anagrafica] = $voce;

            if ($anagrafica !== null) {
                $risultato[] = $voce;

                continue;
            }

            $chiave = $immobile->getKey();

            if (! isset($solidali[$chiave])) {
                $solidali[$chiave] = [$saldo, $immobile, null, 1];

                continue;
            }

            /** @var CanonicalSaldo $accumulato */
            $accumulato = $solidali[$chiave][0];

            $solidali[$chiave][0] = new CanonicalSaldo(
                immobileRef: $accumulato->immobileRef,
                soggettoNome: null,
                importoCents: $accumulato->importoCents + $saldo->importoCents,
                daTitolareCessato: $accumulato->daTitolareCessato || $saldo->daTitolareCessato,
                rigaSorgente: $accumulato->rigaSorgente,
                // Trovato dalla revisione avversariale: mancava qui, e il costruttore lo
                // porta a `false` di default. Un saldo nato da «dividi nome doppio» che si
                // fonde con un saldo di un titolare cessato sulla stessa unità perdeva in
                // silenzio l'avviso e la descrizione che lo segnalano.
                daNomeDiviso: $accumulato->daNomeDiviso || $saldo->daNomeDiviso,
            );
            $solidali[$chiave][3]++;
        }

        foreach ($solidali as $voce) {
            $risultato[] = [$voce[0], $voce[1], $voce[2]];
        }

        return $risultato;
    }

    /**
     * Il controllo che rende l'importazione difendibile.
     *
     * Confronto **esatto**, in centesimi interi. Una tolleranza qui sarebbe un centesimo che si
     * perde a ogni migrazione, cioè la cosa che nessuno ritrova più — è la stessa scelta già
     * fatta per il riparto manuale del pregresso in `CreatePianoRateRequest`.
     */
    private function verificaQuadratura(CanonicalSaldiApertura $dati): ?Rilievo
    {
        $scarto = $dati->scartoCents();

        if ($scarto === null || $scarto === 0) {
            return null;
        }

        return Rilievo::errore(
            'saldi.non_quadrano',
            sprintf(
                'I saldi non quadrano con il totale del riparto: la stampa dichiara %s, le righe che '
                .'ho letto fanno %s, %s %s.',
                MoneyHelper::format($dati->totaleRiferimentoCents),
                MoneyHelper::format($dati->sommaRigheCents() + $dati->arrotondamentiCents),
                $scarto > 0 ? 'ne mancano' : 'ce ne sono in più',
                MoneyHelper::format(abs($scarto)),
            ),
            'Non importo niente finché non torna: un saldo sbagliato si trascina in ogni riparto '
            .'successivo e non si nota più. Controlla di aver esportato il riparto completo, '
            .'senza filtri e senza righe cancellate a mano.',
        );
    }

    /**
     * Il riparto stampa solo il progressivo (`01`, `016`), non la terna dell'elenco unità.
     *
     * Su un condominio con una palazzina sola è sufficiente; con più palazzine è **ambiguo**, e
     * l'ambiguità non si risolve indovinando: si chiede. È la trappola 17, che i file veri hanno
     * mostrato solo quando si è arrivati a incrociare due report diversi.
     *
     * @param  array<string, Model>  $unita  chiave `palazzina-gruppo-progressivo` → immobile
     * @param  list<Rilievo>  $rilievi
     */
    private function risolviUnita(CanonicalSaldo $saldo, array $unita, array &$rilievi): ?Model
    {
        $cercato = ltrim(trim($saldo->immobileRef), '0');

        $candidati = [];

        foreach ($unita as $chiave => $immobile) {
            $pezzi = explode('-', $chiave);
            $progressivo = ltrim(end($pezzi), '0');

            if ($progressivo === $cercato) {
                $candidati[$chiave] = $immobile;
            }
        }

        if (count($candidati) === 1) {
            return reset($candidati);
        }

        $rilievi[] = count($candidati) === 0
            ? Rilievo::errore(
                'saldi.unita_non_trovata',
                sprintf('Il riparto porta un saldo per l\'unità «%s», che non è fra quelle importate.', $saldo->immobileRef),
                'Controlla che il riparto e l\'elenco unità vengano dallo stesso condominio e dallo '
                .'stesso esercizio.',
                $saldo->rigaSorgente,
            )
            : Rilievo::errore(
                'saldi.unita_ambigua',
                sprintf(
                    'Il riparto identifica l\'unità solo con il numero «%s», e in questo condominio ce ne sono %d con quel numero (%s).',
                    $saldo->immobileRef,
                    count($candidati),
                    implode(', ', array_keys($candidati)),
                ),
                'Succede sui condomìni con più palazzine: la stampa del riparto non dice a quale '
                .'palazzina appartenga. Indica tu l\'unità giusta, oppure importa i saldi una '
                .'palazzina alla volta.',
                $saldo->rigaSorgente,
            );

        return null;
    }

    /**
     * Si interroga il **database**, non il canonico: la titolarità può esistere perché l'ha
     * appena scritta questo stesso lotto, o perché c'era già da prima (reimportazione,
     * condominio aperto a mano e completato dopo). Fidarsi del solo canonico direbbe «non è
     * titolare» a chi lo è da un anno.
     */
    private function haTitolarita(Model $anagrafica, Model $immobile): bool
    {
        return DB::table('anagrafica_immobile')
            ->where('anagrafica_id', $anagrafica->getKey())
            ->where('immobile_id', $immobile->getKey())
            ->exists();
    }

    /**
     * @param  array<string, Model>  $soggetti
     * @param  list<Rilievo>  $rilievi
     * @param  list<Rilievo>  $avvisi
     */
    private function risolviSoggetto(CanonicalSaldo $saldo, array $soggetti, array &$rilievi, array &$avvisi): ?Model
    {
        $cercato = $this->normalizzaNome($saldo->soggettoNome ?? '');

        foreach ($soggetti as $anagrafica) {
            if ($this->normalizzaNome($anagrafica->nome) === $cercato) {
                return $anagrafica;
            }
        }

        // Il riparto elenca le persone una per una; l'elenco unità può averle in una cella sola
        // («ROSSI G. /  BIANCHI N.», trappola 8). Cercare il nome fra i componenti è una regola
        // spiegabile — non una somiglianza a occhio — e va **detta**, perché è un accostamento
        // che abbiamo fatto noi.
        foreach ($soggetti as $anagrafica) {
            foreach (preg_split('#\s*/\s*#', (string) $anagrafica->nome) ?: [] as $pezzo) {
                if ($this->normalizzaNome($pezzo) === $cercato && $cercato !== '') {
                    $avvisi[] = Rilievo::avviso(
                        'saldi.soggetto_da_nome_composto',
                        sprintf(
                            'Il saldo di «%s» l\'ho attribuito a «%s», che nel file delle unità è scritto in una cella sola.',
                            $saldo->soggettoNome,
                            $anagrafica->nome,
                        ),
                        'Se quelle sono due persone distinte, dividile prima di emettere le rate: '
                        .'il saldo è ora intestato a entrambe insieme.',
                        $saldo->rigaSorgente,
                    );

                    return $anagrafica;
                }
            }
        }

        $rilievi[] = Rilievo::errore(
            'saldi.soggetto_non_trovato',
            sprintf('Il riparto porta un saldo per «%s», che non è fra le persone importate.', $saldo->soggettoNome),
            'Il riparto e l\'elenco unità devono venire dallo stesso condominio. Se la persona '
            .'compare solo nel riparto, importala prima dal livello «Persone».',
            $saldo->rigaSorgente,
        );

        return null;
    }

    private function normalizzaNome(string $nome): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $nome) ?? ''));
    }

    private function gestione(Esercizio $esercizio): ?Gestione
    {
        return Gestione::whereHas('esercizi', fn ($q) => $q->whereKey($esercizio->getKey()))
            ->orderBy('id')
            ->first();
    }
}
