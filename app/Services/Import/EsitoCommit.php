<?php

namespace App\Services\Import;

/**
 * Com'è andata la scrittura di un livello.
 *
 * ## Perché non è un booleano
 *
 * È la lezione della beta.45, applicata prima di pagarla di nuovo: un metodo che torna `false`
 * per cinque motivi diversi è un canale che perde, e chi guarda la schermata vede una pagina
 * che si ricarica senza dire niente.
 *
 * Qui i motivi sono separati fin dall'inizio, e soprattutto **«riuscito» e «era già a posto»
 * sono due esiti distinti**: un condominio già presente in archivio, unito su decisione
 * dell'utente, non è un fallimento — e mostrarlo come tale insegna a diffidare anche degli
 * errori veri.
 */
final readonly class EsitoCommit
{
    /**
     * @param  list<PrerequisitoMancante>  $prerequisitiMancanti
     * @param  list<Rilievo>  $rilievi  ciò che **impedisce** il commit: errori e decisioni sospese
     * @param  list<Rilievo>  $avvisi  ciò che va detto ma non ferma niente
     *
     * I due elenchi sono separati apposta. Tenerli insieme è il modo più facile di trasformare
     * un avviso in un blocco: basta un `if (!empty($rilievi))` scritto senza pensarci, e da quel
     * momento un'informazione utile diventa un muro. È già successo scrivendo questa classe.
     */
    public function __construct(
        public int $creati = 0,
        public int $uniti = 0,
        public int $saltati = 0,
        public array $prerequisitiMancanti = [],
        public array $rilievi = [],
        public array $avvisi = [],
    ) {}

    public static function bloccatoDaPrerequisiti(PrerequisitoMancante ...$mancanti): self
    {
        return new self(prerequisitiMancanti: array_values($mancanti));
    }

    public static function inAttesaDiDecisione(Rilievo ...$rilievi): self
    {
        return new self(rilievi: array_values($rilievi));
    }

    /**
     * Il commit non parte per un dato che non va, non per una decisione in sospeso.
     *
     * Stessa forma di `inAttesaDiDecisione()` e nome diverso apposta: chi legge il codice deve
     * vedere subito se si sta aspettando l'utente o si sta rifiutando un dato. Sono due
     * schermate diverse.
     */
    public static function bloccato(Rilievo ...$rilievi): self
    {
        return new self(rilievi: array_values($rilievi));
    }

    /** Il livello è passato: qualcosa è stato scritto o consapevolmente non scritto. */
    public function riuscito(): bool
    {
        return $this->prerequisitiMancanti === [] && $this->rilievi === [];
    }

    /** Nulla di nuovo è stato creato, ma non perché sia andato storto. */
    public function giaAPosto(): bool
    {
        return $this->riuscito() && $this->creati === 0;
    }

    /**
     * Il livello è stato **saltato** perché il dato non c'era — non si è fermato.
     *
     * ⚠️ È la stessa distinzione che dalla 1.11.0-beta.5 governa `PrerequisitoMancante::$bloccante`,
     * ma mancava **nel rapporto**: `riuscito()` è falso in entrambi i casi, e la schermata di esito
     * lo traduceva in un badge rosso «fermato» anche per un livello che nessuno aveva chiesto di
     * eseguire. Chi non carica il file delle tabelle millesimali vedeva «Tabelle millesimali ·
     * fermato», cioè un allarme su una cosa che non ha sbagliato.
     *
     * Trovato scattando la fotografia della schermata per la guida del sito: nessun test poteva
     * accorgersene, perché il dato era coerente e a essere sbagliato era il significato.
     */
    public function saltato(): bool
    {
        if ($this->prerequisitiMancanti === [] || $this->rilievi !== []) {
            return false;
        }

        foreach ($this->prerequisitiMancanti as $p) {
            if ($p->bloccante) {
                return false;
            }
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'creati' => $this->creati,
            'uniti' => $this->uniti,
            'saltati' => $this->saltati,
            'riuscito' => $this->riuscito(),
            'gia_a_posto' => $this->giaAPosto(),
            'saltato' => $this->saltato(),
            'prerequisiti_mancanti' => array_map(fn (PrerequisitoMancante $p) => $p->toArray(), $this->prerequisitiMancanti),
            'rilievi' => array_map(fn (Rilievo $r) => $r->toArray(), $this->rilievi),
            'avvisi' => array_map(fn (Rilievo $r) => $r->toArray(), $this->avvisi),
        ];
    }
}
