<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Building2, PlayCircle, Calendar, ChevronRight, ChevronDown, CornerLeftUp, ArrowLeft, BookOpen, Menu } from 'lucide-vue-next';
import { Link, router, usePage } from '@inertiajs/vue3';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger, DropdownMenuPortal } from '@/components/ui/dropdown-menu';
import { usePermission } from "@/composables/permissions";
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

export interface Breadcrumb {
  title: string;
  href?: string;
}

export interface GuideItem {
  title: string;
  description: string;
  icon: any;
  colorVariant: 'blue' | 'amber' | 'emerald' | 'slate';
}

const props = defineProps<{
  pageTitle: string;
  pageSubtitle?: string;
  videoUrl?: string | null;
  hasTextGuide?: boolean;
  textGuideTitle?: string;
  textGuides?: { id: string, title: string, icon?: string }[];
  guides: GuideItem[];
  breadcrumbs?: Breadcrumb[];
  
  condominio?: Building; 
  condomini?: (Building & { esercizio_aperto?: { id: number } | null })[];
  
  esercizio?: Esercizio | null;
  esercizi?: Esercizio[];

  // Props opzionali per il pulsante indietro custom
  backUrl?: string | null;
  backText?: string;
}>();

defineEmits(['open-text-guide']);

const colorStyles = {
  blue: 'bg-blue-100/50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 border-blue-200/50 dark:border-blue-800/50',
  amber: 'bg-amber-100/50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400 border-amber-200/50 dark:border-amber-800/50',
  emerald: 'bg-emerald-100/50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400 border-emerald-200/50 dark:border-emerald-800/50',
  slate: 'bg-slate-100/50 text-slate-600 dark:bg-slate-900/30 dark:text-slate-400 border-slate-200/50 dark:border-slate-800/50',
};

const showCondominioDropdown = computed(() => (props.condomini?.length ?? 0) > 1);
const showEsercizioDropdown = computed(() => (props.esercizi?.length ?? 0) > 1);
const hasBreadcrumbs = computed(() => props.breadcrumbs && props.breadcrumbs.length > 0);
const { generatePath } = usePermission();
const isMobileMenuOpen = ref(false);

// --- Logica Navigazione ---
const page = usePage<{ condominio: Building; condomini: (Building & { esercizio_aperto?: { id: number } | null })[] }>();

function selectCondominio(id: string | number) {
  if (!props.condomini || !props.condominio) return;
  const currentUrl = page.url;
  const segments = currentUrl.split('/');
  const selected = props.condomini.find((c) => String(c.id) === String(id));
  const condIndex = segments.findIndex((s) => s === props.condominio!.id.toString());
  if (condIndex !== -1) segments[condIndex] = id.toString();

  // Se l'URL corrente contiene un id esercizio, aggiorniamolo a quello aperto del nuovo condominio
  const esercizioIndex = segments.findIndex((s, i) => segments[i - 1] === 'esercizi');
  if (esercizioIndex !== -1 && selected?.esercizio_aperto?.id) {
    segments[esercizioIndex] = selected.esercizio_aperto.id.toString();
  }
  router.visit(segments.join('/'), { preserveState: false, preserveScroll: true });
}

// --- Logica Esercizio ---
const selectedEsercizio = ref<Esercizio | null>(props.esercizio ?? null);

watch(() => props.condominio?.id, () => {
  if (props.condominio && !props.esercizi?.find(e => e.id === selectedEsercizio.value?.id)) {
    selectedEsercizio.value = props.esercizi?.[0] ?? null;
  }
});

function selectEsercizio(esercizioId: number | string) {
  const currentUrl = page.url;
  const newUrl = currentUrl.replace(/\/esercizi\/\d+/, `/esercizi/${esercizioId}`);
  router.visit(newUrl, { preserveState: false, preserveScroll: true });
}
</script>

<template>
  <div class="space-y-6">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      
      <div>
        <slot name="breadcrumb">
          <nav 
            v-if="hasBreadcrumbs" 
            class="flex flex-wrap md:inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm max-w-full"
          >
            <template v-for="(item, index) in breadcrumbs" :key="index">
              <Link v-if="item.href && index < breadcrumbs!.length - 1" :href="item.href" class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500 hover:text-primary transition-colors whitespace-nowrap">
                {{ item.title }}
              </Link>
              <span v-else class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400 dark:text-slate-500 whitespace-nowrap">
                {{ item.title }}
              </span>
              <ChevronRight v-if="index < breadcrumbs!.length - 1" class="w-3 h-3 shrink-0 text-slate-300 dark:text-slate-700" />
            </template>
          </nav>
        </slot>

        <div v-if="!hasBreadcrumbs && !$slots.breadcrumb" class="pl-5 border-l-2 border-primary py-1">
          <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight leading-none break-words">
              {{ pageTitle }}
          </h1>
          <p v-if="pageSubtitle" class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed max-w-2xl">
              {{ pageSubtitle }}
          </p>
        </div>
      </div>

      <div class="flex flex-col md:items-end gap-2 shrink-0 w-full md:w-auto mt-2 md:mt-0">
        <!-- Pulsante Menu Mobile -->
        <button 
          @click="isMobileMenuOpen = !isMobileMenuOpen" 
          class="md:hidden inline-flex h-8 items-center justify-between gap-2 px-3 w-full rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-xs font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
        >
          <span class="flex items-center gap-2"><Menu class="w-3.5 h-3.5" /> Opzioni</span>
          <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{'rotate-180': isMobileMenuOpen}" />
        </button>

        <!-- Contenitore Pulsanti -->
        <div :class="[
          'w-full md:w-auto flex-col md:flex-row flex-wrap items-stretch md:items-center gap-2',
          isMobileMenuOpen ? 'flex' : 'hidden md:flex'
        ]">
        
        <Link 
            v-if="backUrl"
            :href="backUrl"
            class="inline-flex h-8 items-center justify-center gap-2 px-3 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-xs font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer"
        >
            <ArrowLeft class="w-3.5 h-3.5 text-slate-500" />
            <span>{{ backText || 'Indietro' }}</span>
        </Link>
        
        <template v-if="condominio">
          <DropdownMenu v-if="showCondominioDropdown">
            <DropdownMenuTrigger
              class="inline-flex h-8 items-center justify-center gap-2 px-3 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-xs font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              <Building2 class="w-3.5 h-3.5 text-slate-400" />
              {{ condominio.nome }}
              <ChevronDown class="w-3.5 h-3.5 text-slate-400" />
            </DropdownMenuTrigger>
            <DropdownMenuPortal>
              <DropdownMenuContent align="end" class="min-w-[180px]">
                <DropdownMenuItem v-for="c in condomini" :key="c.id" class="cursor-pointer" :class="{ 'font-semibold text-primary': c.id === condominio.id }" @click="selectCondominio(c.id)">
                  {{ c.nome }}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenuPortal>
          </DropdownMenu>
          <div v-else class="inline-flex h-8 items-center justify-center gap-2 px-3 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-xs font-medium">
            <Building2 class="w-3.5 h-3.5 text-slate-400" />
            <span class="text-slate-700 dark:text-slate-300">{{ condominio.nome }}</span>
          </div>

          <template v-if="esercizio">
            <DropdownMenu v-if="showEsercizioDropdown">
              <DropdownMenuTrigger
                class="inline-flex h-8 items-center justify-center gap-2 px-3 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-xs font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
              >
                <Calendar class="w-3.5 h-3.5 text-slate-400" />
                {{ selectedEsercizio?.nome.toLowerCase() ?? 'Seleziona esercizio' }}
                <ChevronDown class="w-3.5 h-3.5 text-slate-400" />
              </DropdownMenuTrigger>
              <DropdownMenuPortal>
                <DropdownMenuContent align="end" class="min-w-[180px]">
                  <DropdownMenuItem v-for="e in esercizi" :key="e.id" class="cursor-pointer" :class="{ 'font-semibold text-primary': e.id === esercizio?.id }" @click="selectEsercizio(e.id)">
                    {{ e.nome }}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenuPortal>
            </DropdownMenu>
            <div v-else class="inline-flex h-8 items-center justify-center gap-2 px-3 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-xs font-medium">
              <Calendar class="w-3.5 h-3.5 text-slate-400" />
              <span class="text-slate-700 dark:text-slate-300">{{ esercizio.nome }}</span>
            </div>
          </template>
        </template> 

        <a v-if="videoUrl" :href="videoUrl" target="_blank" class="inline-flex h-8 items-center gap-2 text-xs font-bold px-3 py-2 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 border border-red-100 transition-colors dark:bg-red-950/30 dark:text-red-400">
          <PlayCircle class="w-3.5 h-3.5" />
          Video guida
        </a>

        <!-- Menu Guide Multiple -->
        <DropdownMenu v-if="textGuides && textGuides.length > 0">
          <DropdownMenuTrigger class="inline-flex h-8 items-center gap-2 text-xs font-bold px-3 py-2 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-100 transition-colors dark:bg-indigo-950/30 dark:text-indigo-400 outline-none">
            <BookOpen class="w-3.5 h-3.5" />
            Guide
            <ChevronDown class="w-3.5 h-3.5 opacity-70" />
          </DropdownMenuTrigger>
          <DropdownMenuPortal>
            <DropdownMenuContent align="end" class="min-w-[200px]">
              <DropdownMenuLabel class="text-xs font-bold text-slate-500 uppercase tracking-wider">Guide disponibili</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem 
                v-for="guide in textGuides" 
                :key="guide.id" 
                @click="$emit('open-text-guide', guide.id)"
                class="cursor-pointer text-sm font-medium"
              >
                {{ guide.title }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenuPortal>
        </DropdownMenu>

        <!-- Bottone singola guida (legacy support) -->
        <button v-else-if="hasTextGuide" @click="$emit('open-text-guide')" class="inline-flex h-8 items-center justify-center gap-2 text-xs font-bold px-3 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-100 transition-colors dark:bg-indigo-950/30 dark:text-indigo-400">
          <BookOpen class="w-3.5 h-3.5" />
          {{ textGuideTitle || 'Guida' }}
        </button>

        <slot name="actions"></slot>

        <template v-if="condominio">
          <Link 
            :href="generatePath('dashboard')"
            class="inline-flex h-8 items-center justify-center gap-2 px-3 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
          >
            <CornerLeftUp class="w-3.5 h-3.5 text-white" />
            Dashboard social
          </Link>
        </template>

        </div>
      </div>
    </div>

    <div v-if="hasBreadcrumbs || $slots.breadcrumb" class="pl-5 border-l-2 border-primary py-1">
        <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight leading-none">
            {{ pageTitle }}
        </h1>
        <p v-if="pageSubtitle" class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed max-w-4xl">
            {{ pageSubtitle }}
        </p>
    </div>

    <div
      v-if="guides.length > 0"
      class="grid grid-cols-1 md:grid-cols-3 gap-6 p-6 rounded-2xl border border-slate-200/60 bg-slate-50/50 dark:border-slate-800/50 dark:bg-slate-900/20"
    >
      <div v-for="(guide, index) in guides" :key="index" class="space-y-3 relative" :class="{ 'md:px-6 md:border-l border-slate-200 dark:border-slate-800': index > 0 }">
        <div class="flex items-center gap-3">
          <div class="p-1.5 rounded-md border" :class="colorStyles[guide.colorVariant] || colorStyles.slate">
            <component :is="guide.icon" class="w-4 h-4" />
          </div>
          <span class="font-bold text-slate-900 dark:text-white text-sm tracking-tight uppercase">{{ guide.title }}</span>
        </div>
        <p class="text-[13px] text-slate-500 dark:text-slate-400 leading-relaxed">
          {{ guide.description }}
        </p>
      </div>
    </div>

  </div>
</template>