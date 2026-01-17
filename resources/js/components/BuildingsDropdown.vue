<script setup lang="ts">

import { ref, onMounted } from 'vue' // Usa onMounted per caricare subito
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { ChevronDown, CirclePlus, CircleX, Loader2, Settings2, ArrowRight } from 'lucide-vue-next'
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
const showError = ref(false) 
const loading = ref(false)

const { generateRoute } = usePermission()

// Fetch condomini (Eseguito subito al montaggio)
const fetchCondomini = async () => {
  loading.value = true
  try {
    const response = await axios.get('/fetch-condomini')
    condomini.value = response.data

    // Ripristina selezione precedente SOLO all'avvio
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

// Carica dati appena il componente è pronto
onMounted(() => {
    fetchCondomini();
})

// Select condominio
const selectCondominio = (condominio: Building) => {
  selectedCondominio.value = condominio
  localStorage.setItem('selectedCondominioId', condominio.id)
  open.value = false
  showError.value = false // Rimuovi errore se seleziona
}

// Reset
const resetCondominio = () => {
  selectedCondominio.value = null
  localStorage.removeItem('selectedCondominioId')
  showError.value = false
  open.value = false
}

// Navigate
const goToGestionale = (e: Event) => {
  // Evita comportamenti strani del browser
  e.preventDefault();
  e.stopPropagation();

  if (!selectedCondominio.value) {
    showError.value = true // Attiva bordo rosso
    open.value = true      // Apre la tendina per aiutare l'utente
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
  <div class="flex w-full sm:w-auto items-center shadow-sm rounded-md group">
    
    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded="open"
          aria-label="Select Condominio"
          :class="cn(
            // VISUAL MERGE: rounded-r-none e border-r-0 per attaccarlo al bottone destro
            'flex-1 sm:w-[300px] justify-between text-sm py-2 px-3 rounded-r-none border-r-0 focus:z-10 relative transition-colors', 
            // Gestione Errore Visivo
            showError ? 'border-red-500 ring-1 ring-red-500 z-20 hover:bg-red-50' : 'hover:bg-slate-50'
          )"
        >
          <span class="truncate" :class="showError ? 'text-red-500' : ''">
            {{ selectedCondominio?.nome || 'Seleziona condominio...' }}
          </span>
          <ChevronDown class="ml-2 h-4 w-4 shrink-0 opacity-50" :class="showError ? 'text-red-500' : ''" />
        </Button>
      </PopoverTrigger>
      
      <PopoverContent class="w-[300px] p-0" align="start">
        <div v-if="loading && condomini.length === 0" class="flex items-center justify-center py-6">
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

    <Button 
      class="rounded-l-none px-5 font-medium transition-all duration-200" 
      variant="default"
      @click="goToGestionale"
      title="Vai al pannello di gestione"
    >
      Gestione
      
      <Settings2 
        v-if="!selectedCondominio" 
        class="ml-2 h-4 w-4 opacity-70" 
      />
      <ArrowRight 
        v-else 
        class="ml-2 h-4 w-4 animate-in slide-in-from-left-1" 
      />
    </Button>

  </div>
</template>