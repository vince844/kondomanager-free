// composables/useFattureSimili.ts
//
// Decisione D4 (docs/prima_nota_rapida.md), costruita nella 1.11.0-beta.13: due livelli di
// avviso sui duplicati, mai bloccanti. Questo composable interroga
// GET /gestionale/{condominio}/fetch-fatture-simili — la stessa forma di useCapitoliConti.ts,
// che è il precedente di casa per una fetch-* chiamata da un modulo del gestionale.
import { ref } from 'vue'
import axios from 'axios'

export interface FatturaSimile {
  id: number
  numero_documento: string
  data_documento: string
  totale_documento: number
  motivo: 'forte' | 'standard'
  is_pregresso: boolean
}

export function useFattureSimili() {
  const simili = ref<FatturaSimile[]>([])
  const isLoading = ref(false)

  // ⚠️ Non un flag `isLoading` da solo: due chiamate ravvicinate (l'utente digita, cambia
  // fornitore, ridigita) possono rispondere in ordine diverso da quello in cui sono partite —
  // è la stessa classe di corsa già misurata due volte in questa beta sul filtro data
  // (Coda 111 e la guardia `inCorso` di useTabellaServer). Qui la richiesta non si scarta, si
  // ignora se non è più l'ultima: un numero che cresce a ogni chiamata basta a riconoscerla.
  let richiestaCorrente = 0

  const cercaSimili = async (params: {
    condominioId: number | string
    esercizioId: number | string
    fornitoreId: number | string
    numeroDocumento?: string | null
    totaleDocumentoCents?: number | null
    dataDocumento?: string | null
    tipoDocumento?: string
    escludiFatturaId?: number | null
  }) => {
    // Niente fornitore, niente domanda: è lo stato del modulo appena aperto, non un caso da
    // segnalare come "nessun duplicato trovato".
    if (!params.fornitoreId) {
      simili.value = []
      return
    }

    const numeroRichiesta = ++richiestaCorrente
    isLoading.value = true

    try {
      const response = await axios.get(
        route('admin.gestionale.fetch-fatture-simili', { condominio: params.condominioId }),
        {
          params: {
            esercizio_id: params.esercizioId,
            fornitore_id: params.fornitoreId,
            numero_documento: params.numeroDocumento || undefined,
            totale_documento_cents: params.totaleDocumentoCents || undefined,
            data_documento: params.dataDocumento || undefined,
            tipo_documento: params.tipoDocumento || undefined,
            escludi_fattura_id: params.escludiFatturaId || undefined,
          },
        }
      )

      // Una risposta arrivata dopo che il modulo è già cambiato non deve sovrascrivere lo
      // stato con dati vecchi — meglio niente banner per un istante che un banner sbagliato.
      if (numeroRichiesta !== richiestaCorrente) return

      simili.value = Array.isArray(response.data) ? response.data : []
    } catch (err) {
      if (numeroRichiesta !== richiestaCorrente) return
      // Un avviso non bloccante che fallisce in silenzio non deve bloccare la compilazione
      // del modulo: si comporta come "nessun sospetto trovato", non come un errore in faccia
      // a chi sta scrivendo. Il log resta per chi guarda la console.
      console.error('Errore nel controllo duplicati:', err)
      simili.value = []
    } finally {
      if (numeroRichiesta === richiestaCorrente) isLoading.value = false
    }
  }

  const reset = () => {
    richiestaCorrente++
    simili.value = []
    isLoading.value = false
  }

  return { simili, isLoading, cercaSimili, reset }
}
