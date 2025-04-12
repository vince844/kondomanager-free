<script setup lang="ts">
import {
  Tabs,
  TabsContent,
} from '@/components/ui/tabs'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { 
  House, 
  CircleArrowDown, 
  CircleArrowRight, 
  CircleArrowUp, 
  CircleAlert,
  CircleCheck,
  CircleX,
  History,
  ListCheck, 
  ListX, 
} from 'lucide-vue-next'
import type { Segnalazione } from '@/types/segnalazioni';

const props = defineProps<{
  segnalazione: Segnalazione
}>()

// Icon mappings for priority
const priorityIcons = {
  bassa: CircleArrowDown,
  media: CircleArrowRight,
  alta: CircleArrowUp,
  urgente: CircleAlert,
} as const

// Icon mappings for status
const statusIcons = {
  aperta: CircleCheck,
  chiusa: CircleX,
  'in lavorazione': History,
} as const

</script>

<template>
  <Tabs default-value="overview" class="space-y-4 mb-4">
    <TabsContent value="overview" class="space-y-4">
      <div class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">

        <!-- Condominio Card -->
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-md font-bold">
              Condominio 
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div class="flex items-center gap-2 text-2xl font-semibold text-primary">
              <House class="w-5 h-5 text-muted-foreground" />
              <div class="capitalize text-lg text-muted-foreground">
                {{ segnalazione.condominio.full.nome }}
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Priority Card -->
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-md font-bold">
              Priorità segnalazione
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div class="flex items-center gap-2 text-2xl font-semibold text-primary">
              <component 
                :is="priorityIcons[segnalazione.priority]" 
                class="w-5 h-5 text-muted-foreground" 
              />
              <div class="capitalize text-lg text-muted-foreground">
                {{ segnalazione.priority }}
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Status Card -->
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-md font-bold">
              Stato segnalazione
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div class="flex items-center gap-2 text-2xl font-semibold text-primary">
              <component 
                :is="statusIcons[segnalazione.stato]" 
                class="w-5 h-5 text-muted-foreground" 
              />
              <div class="capitalize text-lg text-muted-foreground">
                {{ segnalazione.stato }}
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Publication Card -->
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-md font-bold">
              Stato pubblicazione
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div class="flex items-center gap-2 text-2xl font-semibold text-primary">
              <component 
                :is="segnalazione.is_published ? ListCheck : ListX" 
                :class="[
                  'text-muted-foreground',
                  segnalazione.is_published ? 'w-5 h-5' : 'w-5 h-5'
                ]" 
              />
              <div class="capitalize text-lg text-muted-foreground">
                {{ segnalazione.is_published ? 'Pubblicata' : 'Bozza' }}
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </TabsContent>
  </Tabs>
</template>