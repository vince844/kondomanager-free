// composables/useCapitoli.ts
import { ref } from 'vue'
import axios from 'axios'

export interface CapitoloDropdown {
  id: number;
  nome: string;
}

export function useCapitoliConti() {
  const capitoli = ref<CapitoloDropdown[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  const fetchCapitoliConti = async (
    condominioId: string | number, 
    pianoContoId: string | number
  ) => {
    try {
      isLoading.value = true
      error.value = null
      
      const response = await axios.get(route('admin.gestionale.fetch-capitoli-conti', {
        condominio: condominioId
      }), {
        params: {
          piano_conto_id: pianoContoId
        }
      })
      
      if (response.data && Array.isArray(response.data)) {
        capitoli.value = response.data.map((item: any) => ({
          id: item.id,
          nome: item.nome,
        }))
        
        return capitoli.value

      } else {
        throw new Error('Formato dati non valido')
      }
    } catch (err: any) {
      let errorMessage = 'Errore nel caricamento dei capitoli'
      
      error.value = errorMessage
      
      // Solo log in console, nessun toast
      console.error('Errore nel caricamento dei capitoli:', err)
      
      capitoli.value = []
      return []
    } finally {
      isLoading.value = false
    }
  }

  const reset = () => {
    capitoli.value = []
    isLoading.value = false
    error.value = null
  }

  return {
    capitoli,
    isLoading,
    error,
    fetchCapitoliConti,
    reset
  }
}