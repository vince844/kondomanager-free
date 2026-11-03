<?php

namespace App\Services\Import;

use App\Models\Condominio;

/**
 * La risposta alla domanda «si può ancora annullare?», con il **perché** attaccato.
 *
 * ⚠️ **Non è un `bool`, ed è una scelta.** La lezione della beta.45 su questo progetto: *un metodo
 * che torna `bool` quando i rami che portano al `false` sono più di due sta nascondendo
 * l'informazione che serve all'interfaccia.* Qui i rami sono tre — non è arrivata in fondo, il
 * condominio preesisteva, qualcuno ci ha già lavorato — e ognuno ha un rimedio diverso.
 *
 * ⛔ E soprattutto: **mai un pulsante grigio senza spiegazione.** Una diagnosi senza cura lascia
 * l'amministratore peggio di prima, perché ora sa di avere un problema e non ha niente da farne.
 */
final class VerdettoAnnullamento
{
    /**
     * @param  array<string, int>  $impedimenti  etichetta => quante cose lo impediscono
     * @param  array<string, int>  $conteggi     livello => quante cose spariranno
     */
    private function __construct(
        public readonly bool $possibile,
        public readonly ?string $motivo = null,
        public readonly ?string $aiuto = null,
        public readonly array $impedimenti = [],
        public readonly array $conteggi = [],
        public readonly ?Condominio $condominio = null,
    ) {}

    /** @param array<string, int> $conteggi */
    public static function si(Condominio $condominio, array $conteggi): self
    {
        return new self(possibile: true, conteggi: $conteggi, condominio: $condominio);
    }

    /** @param array<string, int> $impedimenti */
    public static function no(string $motivo, string $aiuto, array $impedimenti = []): self
    {
        return new self(possibile: false, motivo: $motivo, aiuto: $aiuto, impedimenti: $impedimenti);
    }

    /**
     * Quello che va a schermo, e niente di più.
     *
     * ⚠️ **Il condominio non ci entra.** È un model intero e finirebbe nel payload di Inertia con
     * tutte le sue colonne: qui serve solo il nome, che è ciò che la conferma deve nominare perché
     * l'amministratore capisca cosa sta per sparire.
     *
     * @return array<string, mixed>
     */
    public function perLaSchermata(): array
    {
        return [
            'possibile' => $this->possibile,
            'motivo' => $this->motivo,
            'aiuto' => $this->aiuto,
            'impedimenti' => $this->impedimenti,
            'conteggi' => $this->conteggi,
            'condominio' => $this->condominio?->nome,
        ];
    }
}
