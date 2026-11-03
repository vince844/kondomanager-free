<?php

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Un blocco DatiRitenuta, a livello di documento (non di riga). Importo in
 * centesimi. Nessuna delle tre fixture ufficiali dell'Agenzia ne contiene
 * uno: verificato il 01/09/2026, va provato con una fixture nostra
 * validata contro l'XSD (docs/lettura_xml_fatture_passive.md).
 */
class FatturaPaRitenuta
{
    /**
     * I due soli `TipoRitenuta` che sono davvero **ritenute d'acconto**.
     *
     * ⚠️ Lo schema usa lo stesso blocco `<DatiRitenuta>` per sei cose diverse, e solo
     * queste due lo sono: RT03 è un contributo INPS, RT04 ENASARCO, RT05 ENPAM, RT06 un
     * altro contributo previdenziale. Non sono denaro che il condominio trattiene e versa
     * all'Erario con l'F24 — è il fornitore a versarli al proprio ente — e infatti
     * `App\Enums\Fiscale\TipoRitenuta` non ha nessun case per loro.
     *
     * Confonderli costa in due direzioni: si tratterrebbe al fornitore denaro che non
     * spetta all'Erario, e finirebbe in una delega F24 con un codice tributo inventato.
     */
    private const TIPI_RITENUTA_ACCONTO = ['RT01', 'RT02'];

    public function __construct(
        public readonly ?string $tipoRitenuta,
        public readonly int $importoCents,
        public readonly float $aliquota,
        public readonly ?string $causalePagamento,
    ) {
    }

    /**
     * ⚠️ Un tipo **assente** non è una ritenuta d'acconto.
     *
     * Lo XSD lo impone (`minOccurs="1"`), ma il DTO lo ammette `null` e il parser rifiuta
     * il file solo se mancano importo o aliquota: su un dato che arriva da fuori la
     * disciplina è trattare il non determinato come non determinato, mai come il caso
     * comodo. Qui il caso comodo sarebbe «è una ritenuta», e porterebbe a trattenere.
     */
    public function isRitenutaAcconto(): bool
    {
        return $this->tipoRitenuta !== null
            && in_array(strtoupper($this->tipoRitenuta), self::TIPI_RITENUTA_ACCONTO, true);
    }
}
