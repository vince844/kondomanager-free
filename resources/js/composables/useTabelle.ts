// composables/useTabelle.ts
import { ref } from 'vue'
import axios from 'axios'
import { usePermission } from '@/composables/permissions'
import type { TabellaDropdown } from '@/types/gestionale/tabelle'

export function useTabelle() {
  const tabelle = ref<TabellaDropdown[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const { generateRoute } = usePermission();
  
  const fetchTabelle = async (condominioId: string | number) => {
    try {
      isLoading.value = true
      error.value = null
      
      const response = await axios.get(route(generateRoute('gestionale.fetch-tabelle'), {
        condominio: condominioId
      }))
      
      if (response.data && Array.isArray(response.data)) {
        tabelle.value = response.data.map((item: any) => ({
          id: item.id,
          nome: item.nome,
        }))
        
        return tabelle.value

      } else {

        throw new Error('Formato dati non valido')

      }

    } catch (err: any) {

      let errorMessage = 'Errore nel caricamento dei capitoli'
      
      error.value = errorMessage
      
      // Solo log in console, nessun toast
      console.error('Errore nel caricamento dei capitoli:', err)
      
      tabelle.value = []
      return []

    } finally {
      isLoading.value = false
    }
  }

  const reset = () => {
    tabelle.value = []
    isLoading.value = false
    error.value = null
  }

  return {
    tabelle,
    isLoading,
    error,
    fetchTabelle,
    reset
  }
}