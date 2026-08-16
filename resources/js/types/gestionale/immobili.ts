import { Palazzina } from './palazzine';
import { Scala } from './scale';
import { TipologiaImmobile } from './tipologie-immobili';
import { AnagraficaWithPivot } from '../anagrafiche';

export interface Immobile {
    id: number
    nome: string
    descrizione: string
    note: string,
    codice_interno: string,
    superficie: number,
    comune_catasto: string,
    codice_catasto: string,
    foglio_catasto: string,
    particella_catasto: string,
    subalterno_catasto: string,
    sezione_catasto: string,
    attivo: boolean,
    numero_vani: number,
    interno: string,
    piano: string,
    palazzina: Palazzina,
    scala: Scala,
    tipologia: TipologiaImmobile,
    anagrafiche: AnagraficaWithPivot[]

    /**
     * Il legame «Pertinenza di» (beta.53), nelle sue due forme **alternative**: l'unità principale
     * è in questo condominio, oppure sta fuori e si scrive in chiaro — il caso dei parcheggi
     * vincolati, che alla vendita vanno destinati a un'unità nello stesso comune.
     *
     * `pertinenza_di` e `pertinenze_count` escono solo dove il controller li carica: l'elenco unità
     * sì, il modulo no, che dell'id ha abbastanza.
     */
    pertinenza_di_immobile_id?: number | null,
    pertinenza_di_esterna?: string | null,
    pertinenza_di?: { id: number, nome: string, interno: string | null } | null,
    pertinenze_count?: number,
}
