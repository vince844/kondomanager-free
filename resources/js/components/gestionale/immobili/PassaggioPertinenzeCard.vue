<script setup lang="ts">
/**
 * «Applica lo stesso passaggio anche a: ☐ Box 12 ☐ Cantina 7» — le pertinenze collegate all'unità,
 * proposte con le caselle **non spuntate** (D5 di `docs/pertinenze_vendita_locazione.md`).
 *
 * L'art. 818 co. 1 c.c. fa seguire le pertinenze al bene principale **fra le parti dell'atto**; verso
 * il condominio conta il titolo trasmesso (art. 63 co. 5 disp. att. c.c.). Una casella preselezionata
 * sarebbe un automatismo silenzioso che sposta rate, saldi e conguagli: si spunta ciò che il titolo in
 * mano comprende, e basta.
 */
import { Link2 } from 'lucide-vue-next';
import BadgeRuolo from '@/components/gestionale/immobili/BadgeRuolo.vue';
import type { PertinenzaCollegata } from '@/types/gestionale/passaggi';

defineProps<{
  pertinenze: PertinenzaCollegata[];
  /** Rilievo L7 della Fase 1-bis della .44: nella successione le pertinenze passano agli eredi con l'unità, senza un «atto» da leggere. */
  successione?: boolean;
  /** Nel legato le pertinenze vanno a chi riceve l'unità, non agli eredi (giro sulle correzioni, GC16). */
  legato?: boolean;
}>();

const selezionate = defineModel<number[]>({ default: () => [] });

function toggle(id: number, checked: boolean) {
  const set = new Set(selezionate.value);
  checked ? set.add(id) : set.delete(id);
  selezionate.value = Array.from(set);
}
</script>

<template>
  <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 p-4 space-y-3">
    <div class="flex items-center gap-2">
      <Link2 class="w-4 h-4 text-slate-400" />
      <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">Applica lo stesso passaggio anche a:</p>
    </div>

    <ul class="space-y-2">
      <li v-for="p in pertinenze" :key="p.id">
        <label class="flex items-start gap-3 cursor-pointer select-none rounded-md p-2 -m-2 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
          <input
            type="checkbox"
            :checked="selezionate.includes(p.id)"
            @change="toggle(p.id, ($event.target as HTMLInputElement).checked)"
            class="w-4 h-4 mt-0.5 accent-slate-900 dark:accent-slate-300 rounded border-slate-300 focus:ring-slate-500 cursor-pointer shrink-0"
          />
          <span class="flex flex-col min-w-0">
            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ p.etichetta || p.nome }}</span>
            <span v-if="p.titolari.length" class="flex flex-wrap items-center gap-1.5 mt-1 text-[11px] text-slate-500 dark:text-slate-400">
              oggi di
              <template v-for="(t, i) in p.titolari" :key="i">
                <span class="font-medium text-slate-700 dark:text-slate-300">{{ t.nome }}</span>
                <BadgeRuolo :ruolo="t.tipologia" taglia="sm" />
              </template>
            </span>
            <span v-else class="text-[11px] italic text-slate-400 mt-1">nessun titolare registrato</span>
          </span>
        </label>
      </li>
    </ul>

    <p v-if="successione" class="text-[11px] leading-relaxed text-slate-500 dark:text-slate-400 border-t border-dashed border-slate-200 dark:border-slate-700 pt-3">
      <template v-if="legato">Con il legato le pertinenze passano a chi riceve l'unità insieme all'unità. Togli la spunta solo se il testamento le assegna ad altri.</template>
      <template v-else>Con la successione le pertinenze passano agli eredi insieme all'unità. Togli la spunta solo se il testamento o la divisione le assegnano ad altri.</template>
    </p>
    <p v-else class="text-[11px] leading-relaxed text-slate-500 dark:text-slate-400 border-t border-dashed border-slate-200 dark:border-slate-700 pt-3">
      L'art. 818 co. 1 c.c. fa seguire le pertinenze all'unità principale, salvo diversa disposizione dell'atto.
      Spunta solo ciò che il titolo che hai in mano comprende: verso il condominio conta il titolo, non la presunzione.
    </p>
  </div>
</template>
