import { ref } from 'vue';
import axios from 'axios';

interface CategoriaEvento {
  id: number
  name: string
}

export function useCategorieEventi() {
  const categorie = ref<{ label: string; value: string }[]>([])
  const isLoading = ref(false)
  const isLoaded = ref(false)
  const error = ref<Error | null>(null)

  const loadCategorie = async () => {
    if (isLoaded.value || isLoading.value) return

    isLoading.value = true
    error.value = null

    try {
      const response = await axios.get(route('admin.categorie.eventi'))

      categorie.value = response.data.map((categoria: CategoriaEvento) => ({
        label: categoria.name,
        value: categoria.id,
      }))

      isLoaded.value = true
    } catch (err: any) {
      error.value = err
      console.error('Errore nel caricamento delle categorie eventi:', err)
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
