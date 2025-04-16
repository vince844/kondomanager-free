import { ref } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'

export function useCondominiOptions() {
  const condomini = ref<{ label: string; value: string }[]>([])
  const isLoading = ref(false)

  const loadCondominiOptions = async () => {
    if (condomini.value.length) return
    isLoading.value = true
    try {
      const { data } = await axios.get(route('condomini.options'))
      condomini.value = data
    } catch (error) {
      console.error('Failed to load condomini options', error)
    } finally {
      isLoading.value = false
    }
  }

  return {
    condomini,
    isLoading,
    loadCondominiOptions
  }
}
