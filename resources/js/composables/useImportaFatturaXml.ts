// composables/useImportaFatturaXml.ts
//
// Beta.14, decisione 1 di apertura («due porte, una stanza»,
// docs/lettura_xml_fatture_passive.md): legge l'XML già selezionato nel campo Allegato
// del form e restituisce i dati per precompilarlo — non crea niente da solo.
import { ref } from 'vue'
import axios from 'axios'

export interface RigaImportataXml {
  descrizione: string
  importo_imponibile: number
  aliquota_iva: number
  /**
   * ⚠️ **Presente solo sulle righe che il file classifica**, oggi quelle del contributo
   * cassa previdenziale: il server lo calcola dal campo `<Ritenuta>` di
   * `DatiCassaPrevidenziale` (`ImportaFatturaXmlController::righeDaContributiCassa()`).
   * Assente significa «concorre», che è il default di tutta la catena — per questo si
   * legge sempre `!== false` e mai `=== true`: una riga senza il campo deve entrare in
   * base, una riga che il file dichiara esclusa deve restarne fuori.
   */
  concorre_base_ritenuta?: boolean
}

export interface CandidatoFornitoreXml {
  id: number
  ragione_sociale: string
}

export interface EsitoImportazioneXml {
  documento: {
    tipo_documento: 'fattura' | 'nota_credito'
    numero_documento: string
    data_documento: string
    data_scadenza: string | null
    modalita_pagamento: string | null
    iban_fornitore: string | null
    /**
     * I totali che il documento dichiara di sé nei blocchi DatiRiepilogo, in euro.
     * Non sono la somma delle righe: servono al pannello del debito pregresso, che
     * accetta un imponibile solo e un'aliquota sola. Vedi
     * `FatturaPaFattura::imponibileDichiaratoCents()`.
     */
    imponibile_dichiarato?: number
    imposta_dichiarata?: number
    aliquota_effettiva?: number
  }
  righe: RigaImportataXml[]
  ritenuta: {
    tipo: string | null
    importo: number
    aliquota: number
    causale_pagamento: string | null
  } | null
  fornitore: {
    esito: 'trovato' | 'ambiguo' | 'non_trovato'
    candidati: CandidatoFornitoreXml[]
    letto_da_xml: {
      denominazione: string
      partita_iva: string | null
      partita_iva_paese: string | null
      codice_fiscale: string | null
      indirizzo: string | null
      cap: string | null
      comune: string | null
      provincia: string | null
      nazione: string | null
      email: string | null
      regime_forfetario: boolean
    }
  }
  avvisi: {
    lotto_con_altri_documenti: number
    righe_non_quadrano_col_riepilogo: boolean
    scarto_righe_riepilogo_cents: number
    /**
     * I tipi previdenziali dichiarati dal file (RT03…RT06) — contributo INPS, ENASARCO,
     * ENPAM. Lo schema li mette nello stesso blocco `<DatiRitenuta>` delle ritenute
     * d'acconto, ma sono un'altra cosa: li versa il fornitore al proprio ente, non il
     * condominio con l'F24. Non li trattiamo e non entrano nel campo `ritenuta`; l'elenco
     * serve solo a poterlo dire, perché tacerli lascerebbe una differenza inspiegata fra
     * il totale del file e quello a schermo.
     */
    contributi_previdenziali_dichiarati?: string[]
  }
}

export function useImportaFatturaXml() {
  const isLoading = ref(false)
  const errore = ref<string | null>(null)

  const importa = async (condominioId: number | string, file: File): Promise<EsitoImportazioneXml | null> => {
    isLoading.value = true
    errore.value = null

    try {
      const dati = new FormData()
      dati.append('file', file)

      const response = await axios.post<EsitoImportazioneXml>(
        route('admin.gestionale.fatture.importa-xml', { condominio: condominioId }),
        dati
      )

      return response.data
    } catch (err: any) {
      // ⚠️ Trovato dalla revisione avversariale della beta.14: un 422 può avere DUE forme,
      // non una. Il parser (FatturaPaParseException) risponde con `data.errore` — la forma
      // che questo composable leggeva. Ma `ImportaFatturaXmlRequest` valida anche la
      // dimensione del file PRIMA che il parser veda un byte (`max:` — vedi
      // LimiteCaricamento::regolaMax()), e un 422 di validazione Laravel ha invece
      // `data.errors.file[0]`: il messaggio già localizzato («il file supera 10 MB») finiva
      // scartato in favore del generico qui sotto, che dice meno di quanto il server sapeva.
      errore.value =
        err?.response?.data?.errore ??
        err?.response?.data?.errors?.file?.[0] ??
        'Impossibile leggere il file. Riprova o compila a mano.'

      return null
    } finally {
      isLoading.value = false
    }
  }

  const reset = () => {
    errore.value = null
  }

  return { isLoading, errore, importa, reset }
}
