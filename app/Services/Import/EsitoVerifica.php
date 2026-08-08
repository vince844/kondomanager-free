<?php

namespace App\Services\Import;

/**
 * Il risultato della verifica di un livello: i quattro contatori della schermata S3 e i rilievi
 * che li spiegano.
 *
 * ## I quattro contatori, e perché sono quattro
 *
 * `righe totali · valide · da decidere · errori` (§10.2). Non sono una statistica: sono la
 * risposta a quattro domande che l'amministratore si fa in quest'ordine — *quanto ho caricato?
 * quanto è a posto? cosa devo decidere io? cosa è rotto?*
 *
 * **Gli avvisi non hanno un contatore fra i quattro**, e non è una svista: una riga con un
 * avviso è una riga **valida**. Dargli un quinto riquadro accanto agli errori la farebbe
 * sembrare un problema, e in una schermata che deve distinguere il grave dal trascurabile è
 * proprio ciò che non si vuole. Gli avvisi stanno nella sezione richiudibile sotto, con il loro
 * numero.
 *
 * ## Perché il conteggio è per riga e non per rilievo
 *
 * Una riga può accumulare tre rilievi. Contarli tutti direbbe «tre errori» dove l'amministratore
 * vede una riga da sistemare, e lo manderebbe a cercare tre problemi invece di uno.
 */
final readonly class EsitoVerifica
{
    /**
     * @param  list<Rilievo>  $rilievi
     */
    public function __construct(
        public int $righeTotali,
        public array $rilievi = [],
    ) {}

    /** Righe con almeno un rilievo bloccante di tipo errore. */
    public function errori(): int
    {
        return count($this->righeConSeverita(Severita::Errore));
    }

    /** Righe che aspettano una decisione dell'utente, e nessun errore. */
    public function daDecidere(): int
    {
        $conErrore = $this->righeConSeverita(Severita::Errore);

        return count(array_diff($this->righeConSeverita(Severita::DaDecidere), $conErrore));
    }

    /**
     * Righe pronte a entrare in archivio. Un avviso non toglie validità.
     */
    public function valide(): int
    {
        $bloccate = array_unique(array_merge(
            $this->righeConSeverita(Severita::Errore),
            $this->righeConSeverita(Severita::DaDecidere),
        ));

        return max(0, $this->righeTotali - count($bloccate));
    }

    /** Il numero della sezione richiudibile: gli avvisi si contano per rilievo, non per riga. */
    public function avvisi(): int
    {
        return count($this->perSeverita(Severita::Avviso));
    }

    /**
     * Si può confermare l'import di questo livello?
     *
     * Falso finché resta un errore **o** una decisione in sospeso. Il secondo caso è quello che
     * un validatore binario sbaglierebbe: una decisione non presa non è un errore, ma
     * procedere senza prenderla significa sceglierla al posto dell'utente.
     */
    public function confermabile(): bool
    {
        foreach ($this->rilievi as $rilievo) {
            if ($rilievo->severita->bloccaConferma()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Rilievo>
     */
    public function perSeverita(Severita $severita): array
    {
        return array_values(array_filter(
            $this->rilievi,
            fn (Rilievo $r) => $r->severita === $severita,
        ));
    }

    public function con(Rilievo ...$rilievi): self
    {
        return new self($this->righeTotali, [...$this->rilievi, ...$rilievi]);
    }

    /**
     * La forma che la schermata S3 consuma.
     */
    public function toArray(): array
    {
        return [
            'contatori' => [
                'totali' => $this->righeTotali,
                'valide' => $this->valide(),
                'da_decidere' => $this->daDecidere(),
                'errori' => $this->errori(),
            ],
            'avvisi' => $this->avvisi(),
            'confermabile' => $this->confermabile(),
            'rilievi' => array_map(fn (Rilievo $r) => $r->toArray(), $this->rilievi),
        ];
    }

    /**
     * I numeri di riga toccati da una severità. Le righe `null` (rilievi sul file nel suo
     * insieme, non su una riga) contano come una pseudo-riga zero: un errore sul file blocca
     * la conferma come lo bloccherebbe un errore su una riga.
     *
     * @return list<int>
     */
    private function righeConSeverita(Severita $severita): array
    {
        $righe = [];

        foreach ($this->rilievi as $rilievo) {
            if ($rilievo->severita === $severita) {
                $righe[] = $rilievo->riga ?? 0;
            }
        }

        return array_values(array_unique($righe));
    }
}
