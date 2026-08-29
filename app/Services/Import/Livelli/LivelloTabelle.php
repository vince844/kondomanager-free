<?php

namespace App\Services\Import\Livelli;

use App\Models\Condominio;
use App\Models\ImportBatchItem;
use App\Models\QuotaTabella;
use App\Models\Tabella;
use App\Services\Import\Canonical\CanonicalTabella;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;

/**
 * Livello 7 — le tabelle millesimali.
 *
 * ## La cella vuota non diventa una riga
 *
 * `quote_tabella` ha una colonna `escluso`, che a prima vista sarebbe il posto giusto per dire
 * «questa unità è stata considerata e non partecipa». **Non la legge nessuno**: verificato il
 * 06/08/2026 con `grep -rn "escluso" app/ resources/js/` — compare solo in `$fillable` e
 * `$casts` di `QuotaTabella`. Il motore (`CalcoloQuoteService:655-694`) cicla su *tutte* le
 * righe, somma `valore` e salta quelle con valore ≤ 0.
 *
 * Quindi una riga marcata esclusa ma con un valore verrebbe **conteggiata lo stesso**, e
 * affidarsi a quel flag sarebbe costruire su una funzione promessa e mai mantenuta.
 * L'unità che non partecipa semplicemente **non ha una riga**, che è anche ciò che il motore
 * interpreta correttamente senza sapere niente di tabelle parziali.
 *
 * ## Il tipo si deduce dal nome, ma solo quando è ovvio
 *
 * Danea porta il nome della tabella e niente altro. `ascensore`, `scale`, `riscaldamento`,
 * `acqua` e `lastrico` sono riconoscibili; il resto resta `altro`, che è vero. Assegnare
 * `standard` a tutto sarebbe più ordinato e falso.
 */
final class LivelloTabelle implements LivelloImport
{
    public const CHIAVE = 'tabelle';

    /** Parola nel nome → valore dell'enum `tabelle.tipo`. L'ordine conta: si ferma alla prima. */
    private const TIPI = [
        'ascensore' => 'ascensore',
        'riscaldamento' => 'riscaldamento',
        'acqua' => 'acqua',
        'idric' => 'acqua',
        'lastrico' => 'lastrico',
        'scala' => 'scale',
        'scale' => 'scale',
        'proprieta' => 'standard',
        'generale' => 'standard',
        'millesim' => 'standard',
    ];

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Tabelle millesimali';
    }

    public function dipendeDa(): array
    {
        return [LivelloCondominio::CHIAVE, LivelloUnita::CHIAVE];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $mancanti = [];

        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        if (! $condominio instanceof Condominio || ! Condominio::whereKey($condominio->getKey())->exists()) {
            $mancanti[] = new PrerequisitoMancante(
                'tabelle.condominio_mancante',
                'Le tabelle non possono entrare: manca il condominio.',
                'Importa prima il livello «Condominio».',
            );
        }

        if (! $ctx->haRisolto(LivelloUnita::CHIAVE)) {
            $mancanti[] = new PrerequisitoMancante(
                'tabelle.unita_mancanti',
                'Le tabelle millesimali danno un valore a ogni unità, e le unità non sono in archivio.',
                'Importa prima il livello «Unità immobiliari»: senza, i millesimi non avrebbero '
                .'a chi attaccarsi.',
            );
        }

        $tabelle = $ctx->canonico(self::CHIAVE);

        if (! is_array($tabelle) || $tabelle === []) {
            $mancanti[] = new PrerequisitoMancante(
                'tabelle.dati_assenti',
                'Nessuno dei file caricati contiene le tabelle millesimali.',
                'Serve l\'export «Anagrafica + millesimi» di Danea, oppure il modello «Millesimi».',
            );
        }

        return $mancanti;
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var array<string, CanonicalTabella> $tabelle */
        $tabelle = $ctx->canonico(self::CHIAVE);
        /** @var Condominio $condominio */
        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);
        $unita = $ctx->risoltiMolti(LivelloUnita::CHIAVE);

        $rilievi = [];
        /** @var list<array{0: string, 1: CanonicalTabella, 2: array<string, \Illuminate\Database\Eloquent\Model>}> $daCreare
         *  [nome, dati canonici, quote risolte chiave->immobile]
         */
        $daCreare = [];
        /** @var list<array{0: string, 1: Tabella}> $daSaltare nome + tabella già presente */
        $daSaltare = [];

        // Primo giro: solo lettura. `Tabella::create()` e `QuotaTabella::create()` vivevano
        // **dentro** questo ciclo, prima che si sapesse se un'unità orfana in una tabella
        // successiva avrebbe bloccato l'intero livello — trovato dalla revisione avversariale,
        // stesso schema già corretto in LivelloSoggetti e LivelloTitolarita. Una tabella B con
        // un riferimento irrisolto lasciava comunque scritte la tabella A (pulita) e persino la
        // riga della tabella B stessa, con le sue quote non orfane: il livello risultava
        // «bloccato» ma l'archivio aveva già ricevuto tabelle a metà.
        foreach ($tabelle as $nome => $dati) {
            $esistente = Tabella::where('condominio_id', $condominio->id)
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])
                ->first();

            if ($esistente !== null) {
                $daSaltare[] = [$nome, $esistente];

                continue;
            }

            $quoteRisolte = [];
            $orfane = 0;

            foreach ($dati->quote as $chiaveImmobile => $valore) {
                $immobile = $unita[$chiaveImmobile] ?? null;

                if ($immobile === null) {
                    $orfane++;

                    continue;
                }

                $quoteRisolte[$chiaveImmobile] = $valore;
            }

            if ($orfane > 0) {
                $rilievi[] = Rilievo::errore(
                    'tabella.unita_irrisolte',
                    sprintf('Nella tabella «%s» ci sono %d valori su unità che non trovo in archivio.', $nome, $orfane),
                    'Succede quando il file dei millesimi e quello delle unità non parlano dello '
                    .'stesso condominio, o quando un\'unità è stata saltata. Controlla che i due '
                    .'export vengano dallo stesso condominio.',
                );

                continue;
            }

            $daCreare[] = [$nome, $dati, $quoteRisolte];
        }

        if ($rilievi !== []) {
            return EsitoCommit::bloccato(...$rilievi);
        }

        // Secondo giro: si scrive solo ora che si sa che l'intero livello passa.
        $creati = 0;
        $saltati = 0;
        $avvisi = [];
        $risolti = [];

        foreach ($daSaltare as [$nome, $esistente]) {
            $risolti[$nome] = $esistente;
            $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_SALTATO, $esistente);
            $saltati++;
        }

        foreach ($daCreare as [$nome, $dati, $quoteRisolte]) {
            $tabella = Tabella::create([
                'condominio_id' => $condominio->id,
                'nome' => $nome,
                'tipo' => $this->tipo($nome),
                'quota' => 'millesimi',
                // I millesimi reali arrivano con quattro decimali e la colonna nasce a due:
                // arrotondare in ingresso è una perdita silenziosa.
                'numero_decimali' => $dati->decimaliNecessari(),
                'attiva' => true,
            ]);

            foreach ($quoteRisolte as $chiaveImmobile => $valore) {
                QuotaTabella::create([
                    'tabella_id' => $tabella->id,
                    'immobile_id' => $unita[$chiaveImmobile]->getKey(),
                    'valore' => $valore,
                ]);
            }

            $risolti[$nome] = $tabella;
            $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $tabella);
            $creati++;

            if ($dati->partecipanti() < count($unita)) {
                $avvisi[] = Rilievo::avviso(
                    'tabella.parziale',
                    sprintf(
                        '«%s» riguarda %d unità su %d.',
                        $nome,
                        $dati->partecipanti(),
                        count($unita),
                    ),
                    'Le unità senza valore non vi partecipano: è una tabella parziale, non un '
                    .'dato mancante.',
                );
            }
        }

        // Una tabella che nessun capitolo di spesa usa **non ripartisce niente**: in elenco
        // compare come «Orfana», e il motore non la incontra mai. Danea non dichiara quale
        // spesa va su quale tabella — nei suoi report il nome della tabella e la voce di
        // spesa coincidono («AMMINISTRAZIONE» è entrambe le cose), ma dedurne il collegamento
        // sarebbe indovinare su come si ripartono i soldi.
        //
        // Quindi le tabelle entrano scollegate, e **lo si dice**: è la differenza fra un dato
        // che l'amministratore sa di dover completare e uno che scopre a settembre, quando il
        // riparto non torna.
        if ($creati > 0) {
            $avvisi[] = Rilievo::avviso(
                'tabelle.non_collegate_ai_capitoli',
                sprintf(
                    // Il verbo sta **solo** nel segnaposto: averlo anche qui produceva «4 tabelle
                    // sono entrate entrate», e al singolare «1 tabella è entrata entrate».
                    '%d %s senza collegamento a un capitolo di spesa.',
                    $creati,
                    $creati === 1 ? 'tabella è entrata' : 'tabelle sono entrate',
                ),
                'Finché una tabella non è collegata a un capitolo, nessuna spesa viene ripartita '
                .'con quei millesimi — in elenco le vedrai come «Orfane». Il collegamento si fa '
                .'dal piano dei conti, ed è una scelta contabile che non posso fare io: '
                .'l\'export non dice quale spesa va su quale tabella.',
            );
        }

        $ctx->risolviMolti(self::CHIAVE, $risolti);

        return new EsitoCommit(creati: $creati, saltati: $saltati, avvisi: $avvisi);
    }

    private function tipo(string $nome): string
    {
        $normalizzato = mb_strtolower($nome);

        foreach (self::TIPI as $parola => $tipo) {
            if (str_contains($normalizzato, $parola)) {
                return $tipo;
            }
        }

        // `altro` e non `standard`: «standard» significa la tabella generale di proprietà, e
        // assegnarla a una tabella di cui non sappiamo niente sarebbe più ordinato e falso.
        return 'altro';
    }
}
