import { ref } from 'vue'
import axios from 'axios'

interface CategoriaDocumento {
  id: number
  name: string
}

export function useCategorieDocumenti() {
  const categorie = ref<{ label: string; value: number }[]>([])
  const isLoading = ref(false)
  const isLoaded = ref(false)
  const error = ref<Error | null>(null)

  const loadCategorie = async () => {
    if (isLoaded.value || isLoading.value) return

    isLoading.value = true
    error.value = null

    try {
      const response = await axios.get(route('admin.categorie.documenti'))

      categorie.value = response.data.map((categoria: CategoriaDocumento) => ({
        label: categoria.name,
        value: categoria.id,
      }))

      isLoaded.value = true
    } catch (err: any) {
      error.value = err
      console.error('Errore nel caricamento delle categorie documenti:', err)
    } finally {
      isLoading.value = false
    }
  }

  return {
    categorie,
    isLoading,
    isLoaded,
    error,
    loadCategorie,
  }
}
