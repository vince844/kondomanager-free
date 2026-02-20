// resources/js/composables/useCondomini.ts
import { ref } from 'vue'

export function useCondomini() {
  const condomini = ref<{ label: string; value: string }[]>([])
  const isLoading = ref(false)
  const isLoaded = ref(false)

  const loadCondomini = async () => {
    if (isLoaded.value || isLoading.value) return

    isLoading.value = true
    try {
      // Uso l'URL esatto definito in web.php
      const response = await fetch('/fetch-condomini') 
      
      if (!response.ok) throw new Error('Network response was not ok')
      
      const data = await response.json()
      
      condomini.value = data.map((c: { id: number, nome: string }) => ({
        label: c.nome,
        value: String(c.id) // Stringa per TanStack Table
      }))
      
      isLoaded.value = true
    } catch (error) {
      console.error('Errore durante il caricamento dei condomini:', error)
    } finally {
      isLoading.value = false
    }
  }

  return {
    condomini,
    isLoading,
    loadCondomini
  }
}