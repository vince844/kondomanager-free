<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3'; // 1. Aggiunto l'import del Link
import { Drawer, DrawerClose, DrawerContent, DrawerFooter, DrawerHeader, DrawerTitle, DrawerTrigger } from '@/components/ui/drawer';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { Users, MapPin, ChevronRight, User } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

const props = defineProps<{
    anagrafiche: Array<{ 
        id?: number | string;
        nome: string; 
        indirizzo?: string; 
        email?: string; 
        telefono?: string;
        url?: string; // 2. Aggiunta la prop opzionale url
    }>;
}>();

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
                        {{ trans('condomini.table.residents') }}
                    </span>
                     <span class="text-sm text-slate-500 dark:text-slate-400 font-normal">
                        {{ trans('condomini.table.residents_desc') }}
                    </span>
                </div>
            </div>
            <Badge variant="secondary" class="ml-2 px-3 py-1 text-sm bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border-slate-200 dark:border-slate-700 font-bold">
              {{ anagrafiche.length }} {{ trans('condomini.table.total') }}
            </Badge>
          </DrawerTitle>
        </DrawerHeader>

        <Separator class="mb-2 bg-slate-100 dark:bg-slate-800" />
        
        <div class="px-4 pb-2 pt-2">
          <ScrollArea class="max-h-[calc(100vh-250px)] w-full pr-4">
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
                  <span class="text-base font-bold text-slate-900 dark:text-white leading-tight truncate transition-colors" :class="person.url ? 'group-hover/item:text-primary' : ''">
                    {{ person.nome }}
                  </span>
                  
                  <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 truncate mt-1">
                    <MapPin class="w-3.5 h-3.5 shrink-0 text-slate-400 dark:text-slate-500" />
                    <span class="truncate font-medium italic">
                      {{ person.indirizzo || trans('condomini.placeholder.no_address') }}
                    </span>
                  </div>
                </div>

                <ChevronRight v-if="person.url" class="w-5 h-5 text-slate-300 dark:text-slate-600 group-hover/item:text-primary group-hover/item:translate-x-1 transition-all" />

              </component>
            </div>
          </ScrollArea>
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