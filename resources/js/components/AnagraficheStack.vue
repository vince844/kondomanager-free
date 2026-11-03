<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3'; // 1. Aggiunto l'import del Link
import { Drawer, DrawerClose, DrawerContent, DrawerFooter, DrawerHeader, DrawerTitle, DrawerTrigger } from '@/components/ui/drawer';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Users, MapPin, ChevronRight, User, Percent } from 'lucide-vue-next';
import { trans, transChoice } from 'laravel-vue-i18n';
import { coloreRuolo, etichettaRuolo } from '@/lib/gestionale/ruoli-immobile';

const props = defineProps<{
    anagrafiche: Array<{
        id?: number | string;
        nome: string;
        // ⚠️ Ammette `null` e non solo l'assenza: un indirizzo mancante a database **è** `null`, e
        // costringere chi passa i dati a convertirlo in `undefined` è un giro inutile che si
        // dimentica. Il template lo tratta già come falso, quindi qui cambia solo il tipo.
        indirizzo?: string | null;
        email?: string;
        telefono?: string;
        url?: string; // 2. Aggiunta la prop opzionale url
        /**
         * Ruolo e quota, **facoltativi**: li passa chi li ha.
         *
         * Questo componente è condiviso con l'elenco condomini, dove il legame persona-condominio
         * non ha né ruolo né quota. Renderli obbligatori avrebbe costretto quella pagina a
         * inventarsi due valori, che è il modo in cui nascono i dati finti. Chi non li passa
         * continua a vedere esattamente ciò che vedeva prima.
         */
        ruolo?: string | null;
        quota?: number | string | null;
        /**
         * Postilla sul periodo, quando c'è qualcosa da dire. Oggi la valorizza solo l'elenco
         * unità, con «fino al …» per chi ha una data di fine passata **e** la riga ancora attiva:
         * il motore lo addebita comunque, e nasconderlo renderebbe invisibile ciò che costa.
         */
        nota?: string | null;
    }>;
    title?: string;
    description?: string;
}>();

/**
 * ⚠️ **Colori ed etichette venivano da una copia locale, tolta nella beta.53.** La stessa mappa
 * era scritta a mano in tre punti dell'interfaccia e nessuno dei tre era d'accordo con gli altri.
 * Ora vengono da `@/lib/gestionale/ruoli-immobile`, che è la fonte unica: chi apre il pannello da
 * un elenco e poi entra nella scheda ritrova gli stessi colori, perché sono letteralmente gli
 * stessi.
 */

const maxAvatars = 3; 

const visibleAnagrafiche = computed(() => props.anagrafiche.slice(0, maxAvatars));
const remainingCount = computed(() => props.anagrafiche.length - maxAvatars);

const getInitials = (nome: string) => {
  if (!nome) return '?';
  return nome.split(' ').map(w => w[0]?.toUpperCase()).join('').substring(0, 2);
};

const finalWidth = computed(() => {
    const bubbles = Math.min(props.anagrafiche.length, maxAvatars + 1);
    if (bubbles === 0) return 0;
    return ((bubbles - 1) * 18) + 32 + 12; 
});
</script>

<template>
  <div v-if="!anagrafiche || anagrafiche.length === 0">
    <span class="text-xs italic text-slate-400">—</span>
  </div>

  <Drawer v-else>
    <DrawerTrigger as-child>
      <div class="flex items-center justify-start overflow-visible min-h-[40px]">
        <button 
          class="relative flex items-center h-10 cursor-pointer outline-none hover:opacity-90 transition-all group overflow-visible flex-shrink-0"
          :style="{ width: `${finalWidth}px`, minWidth: `${finalWidth}px` }"
        >
          <div
            v-for="(person, index) in visibleAnagrafiche"
            :key="index"
            class="absolute flex items-center justify-center w-8 h-8 rounded-full border-2 border-white shadow-sm bg-slate-100 text-slate-700 text-[10px] font-bold tracking-tighter dark:bg-slate-800 dark:text-slate-300 dark:border-slate-900 transition-transform duration-300 group-hover:scale-105"
            :style="{ zIndex: 10 + index, left: `${index * 18}px`, top: '50%', transform: 'translateY(-50%)' }"
          >
            {{ getInitials(person.nome) }}
          </div>

          <div
            v-if="remainingCount > 0"
            class="absolute flex items-center justify-center w-8 h-8 rounded-full border-2 border-white shadow-sm bg-slate-200 text-slate-600 text-[10px] font-bold dark:bg-slate-700 dark:text-slate-400 dark:border-slate-900 transition-transform duration-300 group-hover:scale-105"
            :style="{ zIndex: 10 + maxAvatars, left: `${maxAvatars * 18}px`, top: '50%', transform: 'translateY(-50%)' }"
          >
            +{{ remainingCount }}
          </div>
        </button>
      </div>
    </DrawerTrigger>

    <DrawerContent>
      <div class="mx-auto w-full max-w-3xl font-inter"> 
        
        <DrawerHeader class="pb-4 relative">
          <DrawerTitle class="flex items-center justify-between">
            <div class="flex items-center gap-3 text-left">
                <div class="p-2 bg-primary/10 rounded-lg">
                    <Users class="w-6 h-6 text-primary" />
                </div>
                <div class="flex flex-col">
                    <span class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">
                        {{ title || trans('condomini.table.residents') }}
                    </span>
                     <span class="text-sm text-slate-500 dark:text-slate-400 font-normal">
                        {{ description || trans('condomini.table.residents_desc') }}
                    </span>
                </div>
            </div>
            <Badge variant="secondary" class="ml-2 px-3 py-1 text-sm bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border-slate-200 dark:border-slate-700 font-bold">
              <!--
                ⚠️ **Una stringa sola con `transChoice`, non «numero + parola».** Il badge diceva
                «1 Totali», che in italiano con uno non sta in piedi — e si vedeva su tutte e tre le
                pagine che montano questo componente (condomini, unità, e dalla 1.11.0-beta.9 le
                categorie di fornitore). Accostare un numero a una parola fissa funziona solo nella
                lingua in cui il plurale non cambia niente. *(Nella stessa correzione: lo spagnolo
                diceva «Totals», cioè inglese.)*
              -->
              {{ transChoice('condomini.table.total', anagrafiche.length, { count: String(anagrafiche.length) }) }}
            </Badge>
          </DrawerTitle>
        </DrawerHeader>

        <Separator class="mb-2 bg-slate-100 dark:bg-slate-800" />
        
        <div class="px-4 pb-2 pt-2">
          <div class="max-h-[min(320px,calc(100vh-250px))] w-full overflow-y-auto [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
            <div class="flex flex-col gap-3 py-2 pb-24">
              
              <component
                :is="person.url ? Link : 'div'"
                :href="person.url"
                v-for="(person, idx) in anagrafiche" 
                :key="idx" 
                class="group/item relative flex items-center gap-4 p-4 rounded-2xl bg-white dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 shadow-sm transition-all duration-300"
                :class="person.url ? 'hover:shadow-md hover:-translate-y-0.5 hover:border-primary/30 cursor-pointer' : ''"
              >
                <div class="relative shrink-0">
                     <div class="rounded-full w-12 h-12 flex items-center justify-center text-base font-extrabold shadow-sm transition-colors duration-300 bg-slate-100 text-slate-600 group-hover/item:bg-primary/10 group-hover/item:text-primary dark:bg-slate-800 dark:text-slate-300">
                        {{ getInitials(person.nome) }}
                    </div>
                    <div class="absolute -bottom-1 -right-1 bg-white dark:bg-slate-900 rounded-full p-0.5 border border-slate-100 dark:border-slate-800">
                        <User class="w-4 h-4 text-slate-400 dark:text-slate-500 p-0.5 bg-slate-100 dark:bg-slate-800 rounded-full" />
                    </div>
                </div>
                
                <div class="flex flex-col flex-1 min-w-0 text-left">
                  <div class="flex items-center gap-2 min-w-0">
                    <span class="text-base font-bold text-slate-900 dark:text-white leading-tight truncate transition-colors" :class="person.url ? 'group-hover/item:text-primary' : ''">
                      {{ person.nome }}
                    </span>

                    <!--
                      Ruolo e quota accanto al nome, non sotto: sono **il motivo per cui si apre
                      questo pannello** da un elenco di unità — «chi è, a che titolo, per quanto».
                      L'indirizzo resta la riga di sotto, che è contesto e non risposta.

                      La quota compare solo quando c'è **e quando c'è anche un ruolo**: sul
                      legame persona-condominio dell'elenco condomini non esiste, e un «100 %»
                      comparso dal nulla sarebbe un numero inventato.
                    -->
                    <span
                      v-if="person.ruolo"
                      class="shrink-0 px-2 py-1 rounded-md uppercase tracking-widest text-[10px] font-bold"
                      :class="coloreRuolo(person.ruolo)"
                    >
                      {{ etichettaRuolo(person.ruolo) }}
                    </span>

                    <span
                      v-if="person.ruolo && person.quota !== null && person.quota !== undefined"
                      class="shrink-0 inline-flex items-center gap-0.5 text-xs font-semibold text-slate-600 dark:text-slate-400 tabular-nums"
                    >
                      {{ person.quota }}<Percent class="w-3 h-3 text-slate-400" />
                    </span>

                    <!--
                      La postilla è **ambra e non grigia**: non è un dettaglio anagrafico, è un
                      avviso. Un titolare con la data di fine passata continua a essere addebitato
                      dal motore, che le date non le legge — e chi guarda questo pannello deve
                      poterlo vedere senza aprire altro.
                    -->
                    <span
                      v-if="person.nota"
                      class="shrink-0 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                    >
                      {{ person.nota }}
                    </span>
                  </div>

                  <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 truncate mt-1">
                    <MapPin class="w-3.5 h-3.5 shrink-0 text-slate-400 dark:text-slate-500" />
                    <!--
                      ⚠️ **`pr-0.5` non è una spaziatura: tiene l'ultima lettera dentro la scatola.**
                      Il testo è in corsivo dentro un contenitore `truncate`, cioè
                      `overflow: hidden`. I glifi corsivi sbordano a destra oltre la propria
                      larghezza di avanzamento — misurato su «via roma 12»: avanzamento 71,23 px e
                      **1,65 px di sbalzo** — e quel pezzo finisce fuori dalla scatola e viene
                      tagliato. L'ultima cifra si vede rasata.

                      Il difetto è invisibile a `scrollWidth === clientWidth`, che è il controllo
                      istintivo: `scrollWidth` è un intero e **non conta lo sbalzo del corsivo**,
                      quindi dichiara che va tutto bene mentre a schermo manca un pezzo di lettera.
                      Segnalato da Vincenzo guardando la pagina, dopo che una mia verifica
                      programmatica aveva detto il contrario.
                    -->
                    <span class="truncate pr-0.5 font-medium italic">
                      {{ person.indirizzo || trans('condomini.placeholder.no_address') }}
                    </span>
                  </div>
                </div>

                <ChevronRight v-if="person.url" class="w-5 h-5 text-slate-300 dark:text-slate-600 group-hover/item:text-primary group-hover/item:translate-x-1 transition-all" />

              </component>
            </div>
          </div>
        </div>
        
        <DrawerFooter class="absolute bottom-0 left-0 right-0 pt-4 border-t border-slate-100 dark:border-slate-800 bg-white/80 dark:bg-slate-950/80 backdrop-blur-md rounded-b-3xl px-6 pb-6 flex items-center justify-center">
          <DrawerClose as-child>
            <Button 
                variant="default" 
                class="w-fit px-8 bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200 font-bold uppercase text-xs tracking-widest h-11 transition-all shadow-md active:scale-95"
            >
              {{ trans('condomini.dialogs.close_list') }}
            </Button>
          </DrawerClose>
        </DrawerFooter>
        
      </div>
    </DrawerContent>
  </Drawer>
</template>