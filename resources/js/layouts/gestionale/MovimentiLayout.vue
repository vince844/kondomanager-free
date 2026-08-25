<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import NavSezione from '@/components/NavSezione.vue';
import { usePermission } from "@/composables/permissions";
import { LogIn, LogOut, Wallet, Repeat2, FileText, BookOpen, Percent } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

const page = usePage<{ condominio: Building; esercizio?: Esercizio }>();
const condominio = computed(() => page.props.condominio);
const esercizio = computed(() => page.props.esercizio);
const { generatePath } = usePermission();

const topbarNavItems: (LinkItem & { badge?: string })[] = [
    {
        type:  'link',
        icon:  LogIn,
        title: 'Incassi rate',
        href:  generatePath('gestionale/:condominio/movimenti-rate', { condominio: condominio.value.id }),
    },
    {
        type:  'link',
        icon:  FileText,
        title: 'Fatture passive',
        href:  generatePath('gestionale/:condominio/fatture', { condominio: condominio.value.id }),
    },
    {
        type:  'link',
        icon:  LogOut,
        title: 'Pagamenti fornitori',
        href:   generatePath('gestionale/:condominio/pagamenti-fornitori', { condominio: condominio.value.id }),
    },
    {
        type:  'link',
        icon:  Repeat2,
        title: 'Giroconti',
        href:  generatePath('gestionale/:condominio/giroconti', { condominio: condominio.value.id }),
    },
    {
        type:  'link',
        icon:  Percent,
        title: 'Ritenute e F24',
        href:  generatePath('gestionale/:condominio/f24', { condominio: condominio.value.id }),
    },
    {
        type:  'link',
        icon:  BookOpen,
        title: 'Libro Giornale',
        // Senza un esercizio aperto (es. l'amministratore lo ha appena chiuso) non c'è un
        // esercizio su cui costruire la rotta annidata: link disabilitato, come "Prima nota",
        // invece di un href con id 0 che il route model binding rifiuterebbe con un 404.
        href:  esercizio.value
            ? generatePath('gestionale/:condominio/esercizi/:esercizio/scritture', {
                  condominio: condominio.value.id,
                  esercizio:  esercizio.value.id,
              })
            : '#',
        badge: esercizio.value ? undefined : 'Nessun esercizio aperto',
    },
    // La regolazione immediata non è più in barra: vive accanto a "Nuova fattura"
    // nell'elenco fatture — è il fratello minore della fattura, non un registro suo.
    {
        type:  'link',
        icon:  Wallet,
        title: 'Prima nota',
        href:  '#',
        badge: 'In sviluppo',
    },
];

const currentPath = window.location.pathname;
</script>

<template>
    <div class="">
        <NavSezione :items="topbarNavItems" class="mb-4" />

        <!-- Main content -->
        <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
            <section class="w-full">
                <slot />
            </section>
        </div>
    </div>
</template>