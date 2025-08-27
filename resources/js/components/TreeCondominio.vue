<!-- src/components/TreeCondominio.vue -->
<script setup lang="ts">
import { ref } from 'vue';
import { Card, CardContent } from '@/components/ui/card';

const condominio = ref({
  nome: 'Condominio San Marco',
  immobili: [
    { nome: 'Garage Comune' },
    { nome: 'Sala Riunioni' }
  ],
  palazzine: [
    {
      nome: 'Palazzina A',
      immobili: [{ nome: 'Appartamento 1A' }, { nome: 'Appartamento 2A' }],
      scale: [
        { 
          nome: 'Scala A1',
          immobili: [{ nome: 'Appartamento 3A1' }, { nome: 'Appartamento 4A1' }]
        },
        { nome: 'Scala A2', immobili: [] }
      ]
    },
    {
      nome: 'Palazzina B',
      immobili: [],
      scale: [
        { nome: 'Scala B1', immobili: [{ nome: 'Appartamento 1B1' }] }
      ]
    }
  ]
});

const expandedCondominio = ref(true);
const expandedPalazzine = ref<boolean[]>(condominio.value.palazzine.map(() => true));
const expandedScale = ref<boolean[][]>(
  condominio.value.palazzine.map(p => p.scale.map(() => true))
);

function toggleCondominio() {
  expandedCondominio.value = !expandedCondominio.value;
}
function togglePalazzina(i: number) {
  expandedPalazzine.value[i] = !expandedPalazzine.value[i];
}
function toggleScala(i: number, j: number) {
  expandedScale.value[i][j] = !expandedScale.value[i][j];
}
</script>

<template>
  <div class="space-y-2">
    <!-- Condominio -->
    <Card 
      class="border border-gray-200 shadow-sm cursor-pointer bg-white"
      @click="toggleCondominio"
    >
      <CardContent class="py-3 px-4 flex justify-between items-center">
        <span class="text-xl font-bold">{{ condominio.nome }}</span>
        <span :class="expandedCondominio ? 'rotate-90' : ''" class="transition-transform">▶</span>
      </CardContent>
    </Card>

    <div v-if="expandedCondominio" class="space-y-1 pl-4 border-l border-gray-200">
      <!-- Condominio immobili -->
      <Card 
        v-for="(immobile, idx) in condominio.immobili" 
        :key="'condominio-immobile-' + idx" 
        class="border border-gray-200 bg-gray-50"
      >
        <CardContent class="py-2 px-3 text-sm">{{ immobile.nome }}</CardContent>
      </Card>

      <!-- Palazzine -->
      <div v-for="(palazzina, i) in condominio.palazzine" :key="i" class="space-y-1">
        <Card 
          class="border border-gray-200 cursor-pointer bg-gray-50"
          @click="togglePalazzina(i)"
        >
          <CardContent class="py-2 px-3 flex justify-between items-center">
            <span class="text-lg font-semibold">{{ palazzina.nome }}</span>
            <span :class="expandedPalazzine[i] ? 'rotate-90' : ''" class="transition-transform">▶</span>
          </CardContent>
        </Card>

        <div v-if="expandedPalazzine[i]" class="space-y-1 pl-4 border-l border-gray-200">
          <!-- Palazzina immobili -->
          <Card 
            v-for="(immobile, idx) in palazzina.immobili" 
            :key="'palazzina-' + i + '-immobile-' + idx" 
            class="border border-gray-200 bg-gray-100"
          >
            <CardContent class="py-2 px-3 text-sm">{{ immobile.nome }}</CardContent>
          </Card>

          <!-- Scale -->
          <!-- Scale -->
          <div v-for="(scala, j) in palazzina.scale" :key="j" class="space-y-1">
            <Card
              class="border border-gray-200 bg-gray-100"
              :class="scala.immobili.length ? 'cursor-pointer' : ''"
              @click="scala.immobili.length && toggleScala(i, j)"
            >
              <CardContent class="py-2 px-3 flex justify-between items-center">
                <span class="text-sm font-semibold">{{ scala.nome }}</span>
                <span
                  v-if="scala.immobili.length"
                  :class="expandedScale[i][j] ? 'rotate-90' : ''"
                  class="transition-transform"
                >
                  ▶
                </span>
              </CardContent>
            </Card>

            <div v-if="scala.immobili.length && expandedScale[i][j]" class="space-y-1 pl-4 border-l border-gray-200">
              <Card
                v-for="(immobile, idx) in scala.immobili"
                :key="'scala-' + i + '-' + j + '-immobile-' + idx"
                class="border border-gray-200 bg-gray-200"
              >
                <CardContent class="py-2 px-3 text-sm">{{ immobile.nome }}</CardContent>
              </Card>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</template>
