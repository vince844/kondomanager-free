// composables/useFattureRettificabili.ts
//
// Coda 165 (1.11.0-beta.36): le fatture che una nota di credito del fornitore può rettificare, con i motivi della scala
// già calcolati dal server. Interroga GET /gestionale/{condominio}/fetch-fatture-rettificabili — la stessa forma di
// useFattureSimili, compresa la guardia sulla corsa fra due richieste: si cambia fornitore o importo mentre la prima è
// ancora in volo, e la risposta vecchia non deve sovrascrivere quella nuova.
import { ref } from 'vue'
import axios from 'axios'
import type { FatturaRettificabile, RigaNotaPerCandidate } from '@/lib/gestionale/fatture/fatturaRettificata'

export function useFattureRettificabili() {
  const candidate = ref<FatturaRettificabile[]>([])
  const isLoading = ref(false)
  const errore = ref(false)
  let richiestaCorrente = 0

  const carica = async (params: {
    condominioId: number | string
    fornitoreId: number | string | null
    /** Magnitudine della nota, in centesimi: il server la usa per il tetto e per il «di quanto» dei motivi. */
    importoCents?: number
    /** Per «Collega a una fattura»: la via nei motivi dice «collega la nota» invece di «registra la nota». */
    perCollegare?: boolean
    /** La nota già registrata: righe e importo li legge il server (R4 della Fase 1-bis). */
    notaId?: number | null
    /** Le righe della nota del modulo, lorde in centesimi: dove riduce, e di quanto (R4). */
    righe?: RigaNotaPerCandidate[] | null
    /** La fattura dichiarata dal file: il server la segna fra le candidate a ogni caricamento (R8). */
    numeroDichiarato?: string | null
    dataDichiarata?: string | null
    /** La nota del modulo è contestata: il server la tiene fuori da scala e avviso (V3 della verifica). */
    contestata?: boolean
  }) => {
    if (!params.fornitoreId) {
      richiestaCorrente++
      candidate.value = []
      isLoading.value = false
      return
    }

    const numeroRichiesta = ++richiestaCorrente
    isLoading.value = true
    errore.value = false

    try {
      const response = await axios.get(
        route('admin.gestionale.fetch-fatture-rettificabili', { condominio: params.condominioId }),
        {
          params: {
            fornitore_id: params.fornitoreId,
            importo_cents: params.importoCents ? Math.abs(Math.round(params.importoCents)) : undefined,
            per_collegare: params.perCollegare ? 1 : undefined,
            nota_id: params.notaId || undefined,
            righe: params.righe && params.righe.length > 0 ? JSON.stringify(params.righe) : undefined,
            numero_dichiarato: params.numeroDichiarato || undefined,
            data_dichiarata: params.dataDichiarata || undefined,
            contestata: params.contestata ? 1 : undefined,
          },
        }
      )
      if (numeroRichiesta !== richiestaCorrente) return
      candidate.value = Array.isArray(response.data) ? response.data : []
    } catch (err) {
      if (numeroRichiesta !== richiestaCorrente) return
      // Diverso dai simili: qui l'elenco serve a scegliere, e un elenco vuoto per un errore di rete sembrerebbe «nessuna
      // fattura di questo fornitore». Si dice che il caricamento non è riuscito; la nota si registra comunque senza.
      console.error('Errore nel caricamento delle fatture rettificabili:', err)
      candidate.value = []
      errore.value = true
    } finally {
      if (numeroRichiesta === richiestaCorrente) isLoading.value = false
    }
  }

  const reset = () => {
    richiestaCorrente++
    candidate.value = []
    isLoading.value = false
    errore.value = false
  }

  return { candidate, isLoading, errore, carica, reset }
}
