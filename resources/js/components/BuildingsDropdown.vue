<script setup lang="ts">

import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { ChevronDown, CirclePlus, CircleX, Loader2 } from 'lucide-vue-next'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { usePermission } from "@/composables/permissions";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import type { Building } from '@/types/buildings'

// State
const condomini = ref<Building[]>([])
const selectedCondominio = ref<Building | null>(null)
const open = ref(false)
const condominiLoaded = ref(false)
const showError = ref(false) 
const loading = ref(false)

const { generateRoute } = usePermission()

// Fetch condomini
const fetchCondomini = async () => {
  loading.value = true
  try {
    const response = await axios.get('/fetch-condomini')
    condomini.value = response.data
    condominiLoaded.value = true

    const storedId = localStorage.getItem('selectedCondominioId')
    if (storedId) {
      const found = response.data.find((c: Building) => c.id == storedId)
      if (found) selectedCondominio.value = found
    }
  } catch (error) {
    console.error('Errore nel recupero dei condomini:', error)
  } finally {
    loading.value = false
  }
}

// Watch dropdown open to trigger fetch and clear error
watch(open, async (isOpen) => {
  if (isOpen && !condominiLoaded.value) {
    await fetchCondomini()
  }
  if (isOpen) {
    showError.value = false  // clear error when dropdown opens
  }
})

// Select condominio
const selectCondominio = (condominio: Building) => {
  selectedCondominio.value = condominio
  localStorage.setItem('selectedCondominioId', condominio.id)
  open.value = false
  showError.value = false  // clear error on valid selection
}

// Reset
const resetCondominio = () => {
  selectedCondominio.value = null
  localStorage.removeItem('selectedCondominioId')
  showError.value = false
}

// Navigate
const goToGestionale = () => {
  if (!selectedCondominio.value) {
    showError.value = true  // highlight error if nothing selected
    return
  }
  const url = route(generateRoute('gestionale.index'), { condominio: selectedCondominio.value.id });
  router.visit(url);
}

const goToCreateCondominio = () => {
  router.visit(route('condomini.create'))
}
</script>

<template>
  <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
    <Popover v-model:open="open" class="flex-1">
      <PopoverTrigger as-child>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded="open"
          aria-label="Select Condominio"
          :class="cn(
            // full width on mobile, fixed on md+
            'w-full sm:w-[300px] justify-between text-sm py-2 px-3',
            showError ? 'border-red-500 ring-1 ring-red-500' : ''
          )"
        >
          {{ selectedCondominio?.nome || 'Seleziona condominio' }}
          <ChevronDown class="ml-auto h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent class="w-full sm:w-[300px] p-0">
        <div v-if="loading" class="flex items-center justify-center py-6">
          <Loader2 class="h-5 w-5 animate-spin text-gray-500" />
          <span class="ml-2 text-sm text-gray-500">Caricamento...</span>
        </div>
        <Command v-else>
          <CommandInput placeholder="Cerca condominio..." />
          <CommandEmpty>Nessun condominio trovato.</CommandEmpty>

          <CommandList>
            <CommandGroup>
              <CommandItem
                v-for="condominio in condomini"
                :key="condominio.id"
                :value="condominio.nome"
                @select="() => selectCondominio(condominio)"
              >
                {{ condominio.nome }}
              </CommandItem>
            </CommandGroup>
          </CommandList>

          <CommandSeparator />

          <CommandList>
            <CommandGroup>
              <CommandItem
                value="create-condominio"
                @select="() => {
                  open = false
                  goToCreateCondominio()
                }"
              >
                <CirclePlus class="mr-2 h-5 w-5" />
                Crea condominio
              </CommandItem>

              <CommandItem
                value="reset-condominio"
                @select="() => {
                  resetCondominio()
                  open = false
                }"
              >
                <CircleX class="mr-2 h-5 w-5 text-red-600" />
                Reset selezione
              </CommandItem>
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
    <Button class="w-full sm:w-auto text-sm py-2 px-4" @click="goToGestionale">
      Gestione
    </Button>
  </div>
</template>
