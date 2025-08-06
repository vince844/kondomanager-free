<script setup lang="ts">

import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { ChevronDown, CirclePlus } from 'lucide-vue-next'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import type { Building } from '@/types/buildings'

// State
const condomini = ref<Building[]>([])
const selectedCondominio = ref<Building | null>(null)
const open = ref(false)
const condominiLoaded = ref(false)  // track if already fetched

// Fetch condomini
const fetchCondomini = async () => {
  try {
    const response = await axios.get('/fetch-condomini')
    condomini.value = response.data
    condominiLoaded.value = true

    // Restore selected condominio from localStorage
    const storedId = localStorage.getItem('selectedCondominioId')
    if (storedId) {
      const found = response.data.find((c: Building) => c.id == storedId)
      if (found) selectedCondominio.value = found
    }

  } catch (error) {
    console.error('Errore nel recupero dei condomini:', error)
  }
}

// Watch dropdown open to trigger fetch
watch(open, async (isOpen) => {
  if (isOpen && !condominiLoaded.value) {
    await fetchCondomini()
  }
})

// Select condominio
const selectCondominio = (condominio: Building) => {
  selectedCondominio.value = condominio
  localStorage.setItem('selectedCondominioId', condominio.id)
  open.value = false
}

// Reset
const resetCondominio = () => {
  selectedCondominio.value = null
  localStorage.removeItem('selectedCondominioId')
}

// Navigate
const goToGestionale = () => {
  if (!selectedCondominio.value) return
  router.visit(`/gestionale/${selectedCondominio.value.id}`)
}

const goToCreateCondominio = () => {
  router.visit(route('condomini.create'))
}
</script>


<template>
  <div class="flex items-center gap-1">
    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded="open"
          aria-label="Select Condominio"
          :class="cn('w-[200px] justify-between')"
        >
          {{ selectedCondominio?.nome || 'Seleziona condominio' }}
          <ChevronDown class="ml-auto h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent class="w-[200px] p-0">
        <Command>
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
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>

    <Button variant="outline" @click="goToGestionale">
      Vai al gestionale
    </Button>

    <Button
      v-if="selectedCondominio"
      variant="outline"
      @click="resetCondominio"
    >
      Reset
    </Button>
  </div>
</template>
