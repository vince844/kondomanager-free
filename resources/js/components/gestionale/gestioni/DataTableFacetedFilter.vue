<script setup lang="ts">

import { ref, watch, computed, PropType } from 'vue'
import type { Component } from 'vue'
import type { Column } from '@tanstack/vue-table'
import { cn } from '@/lib/utils'
import Badge from '@/components/ui/badge/Badge.vue'
import Button from '@/components/ui/button/Button.vue'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Separator } from '@/components/ui/separator'
import { Check, PlusCircle } from 'lucide-vue-next'

interface Option {
  label: string
  value: string
  icon?: Component
}

const props = defineProps({
  column: {
    type: Object as PropType<Column<any, unknown>>,
    required: true,
  },
  title: String,
  options: {
    type: Array as PropType<Option[]>,
    required: true,
  },
  isLoading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits<{
  (e: 'update:filter', value: string[]): void
  (e: 'open'): void
}>()

const isOpen = ref(false)

watch(isOpen, (val) => {
  if (val) emit('open')
})

const facets = computed(() => {
  return props.column?.getFacetedUniqueValues?.() ?? new Map()
})

const selectedValues = ref(new Set<string>())

watch(
  () => props.column?.getFilterValue?.(),
  (newVal) => {
    selectedValues.value = new Set(Array.isArray(newVal) ? newVal : [])
  },
  { immediate: true }
)

function toggleSelection(optionValue: string) {
  const updatedValues = new Set(selectedValues.value)

  if (updatedValues.has(optionValue)) {
    updatedValues.delete(optionValue)
  } else {
    updatedValues.add(optionValue)
  }

  const valuesArray = Array.from(updatedValues)
  props.column?.setFilterValue(valuesArray.length ? valuesArray : undefined)
  emit('update:filter', valuesArray)
}

function clearFilters() {
  props.column?.setFilterValue(undefined)
  emit('update:filter', [])
}
</script>


<template>
  <Popover v-model:open="isOpen">
    <PopoverTrigger as-child>
      <Button variant="outline" size="sm" class="h-8 border-dashed">
        <PlusCircle class="mr-2 h-4 w-4" />
        {{ title }}
        <template v-if="selectedValues.size > 0">
          <Separator orientation="vertical" class="mx-2 h-4" />
          <Badge variant="secondary" class="rounded-sm px-1 font-normal lg:hidden">
            {{ selectedValues.size }}
          </Badge>
          <div class="hidden space-x-1 lg:flex">
            <Badge
              v-if="selectedValues.size > 2"
              variant="secondary"
              class="rounded-sm px-1 font-normal"
            >
              {{ selectedValues.size }} selezionati
            </Badge>
            <template v-else>
              <Badge
                v-for="option in options.filter(o => selectedValues.has(o.value))"
                :key="option.value"
                variant="secondary"
                class="rounded-sm px-1 font-normal"
              >
                {{ option.label }}
              </Badge>
            </template>
          </div>
        </template>
      </Button>
    </PopoverTrigger>
    <PopoverContent class="w-[250px] p-0" align="start">
      <Command>
        <CommandInput :placeholder="title" />
        <CommandList v-if="props.isLoading">
          <div class="p-4 text-sm text-muted-foreground">Caricamento...</div>
        </CommandList>

        <CommandList>
          <CommandEmpty>Nessun risultato trovato</CommandEmpty>
          <CommandGroup>
            <CommandItem
              v-for="option in options"
              :key="option.value"
              :value="option"
              @select="() => toggleSelection(option.value)"
            >
              <div
                :class="cn(
                  'mr-2 flex h-4 w-4 items-center justify-center rounded-sm border border-primary',
                  selectedValues.has(option.value)
                    ? 'bg-primary text-primary-foreground'
                    : 'opacity-50 [&_svg]:invisible',
                )"
              >
                <Check class="h-4 w-4" />
              </div>
              <component
                :is="option.icon"
                v-if="option.icon"
                class="mr-2 h-4 w-4 text-muted-foreground"
              />
              <span>{{ option.label }}</span>
              <span
                v-if="facets?.get(option.value)"
                class="ml-auto flex h-4 w-4 items-center justify-center font-mono text-xs"
              >
                {{ facets.get(option.value) }}
              </span>
            </CommandItem>
          </CommandGroup>

          <template v-if="selectedValues.size > 0">
            <CommandSeparator />
            <CommandGroup>
              <CommandItem
                :value="{ label: 'Resetta filtri' }"
                class="justify-center text-center"
                @select="clearFilters"
              >
                Resetta filtri
              </CommandItem>
            </CommandGroup>
          </template>
        </CommandList>
      </Command>
    </PopoverContent>
  </Popover>
</template>
