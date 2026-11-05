<script setup lang="ts">
/**
 * Gli estremi del bollo assolto, sotto i quattro pulsanti: il numero del contrassegno o gli estremi dell'autorizzazione, facoltativi, con il
 * segnaposto adatto al modo. Compare solo quando il chiamante lo mostra (con una delle due «Assolta» accesa); sotto il campo il chiamante
 * può mettere le sue note. Il valore lo tiene chi lo usa.
 */
import { Input } from '@/components/ui/input';
import { SEGNAPOSTO_ESTREMI } from '@/lib/gestionale/incassi/ricevute';

defineProps<{ id: string; modo: string }>();
const estremi = defineModel<string>({ default: '' });
</script>

<template>
    <div data-testid="estremi-bollo" class="space-y-1">
        <label :for="id" class="text-xs text-muted-foreground">Estremi (facoltativi)</label>
        <Input :id="id" v-model="estremi" maxlength="200" :placeholder="SEGNAPOSTO_ESTREMI[modo]" class="h-8 text-xs" />
        <slot />
    </div>
</template>
