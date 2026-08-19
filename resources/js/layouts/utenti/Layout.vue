<script setup lang="ts">

import { computed, ref } from 'vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import UtentiGuide from '@/components/guides/UtentiGuide.vue';
import NavSezione from '@/components/NavSezione.vue';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';
import { UsersRound, Drama, KeyRound, Mails, ShieldCheck, Mail, Users } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { LinkItem, BreadcrumbItem } from '@/types';

const props = defineProps<{
    pageTitle?: string;
    pageSubtitle?: string;
    breadcrumbs?: BreadcrumbItem[];
}>();

const sidebarNavItems: LinkItem[] = [
    {
        type: 'link',
        icon: UsersRound,
        title: 'impostazioni.sidebar.users',
        href: '/utenti',
    },
    {
        type: 'link',
        icon: Drama,
        title: 'impostazioni.sidebar.roles',
        href: '/ruoli',
    },
    {
        type: 'link',
        icon: KeyRound,
        title: 'impostazioni.sidebar.permissions',
        href: '/permessi',
    },
    {
        type: 'link',
        icon: Mails,
        title: 'impostazioni.sidebar.invites',
        href: '/inviti',
    }
];

const currentPath = window.location.pathname;

// La guida di sezione sta nel layout e non nella singola pagina: le quattro schermate — utenti,
// ruoli, permessi, inviti — raccontano la stessa storia, e chi arriva sui permessi ha le stesse
// domande di chi arriva sugli utenti.
const mostraGuida = ref(false);

/** I titoli qui sono chiavi di lingua: il componente mostra il testo che riceve, quindi si traduce prima. */
const vociNav = computed(() => sidebarNavItems.map(item => ({ ...item, title: trans(item.title) })));

/**
 * ⚠️ **`computed`, non `const`.** Le tre schede mostravano `users.guides.users_title` al posto del
 * testo: le `trans()` venivano risolte una volta sola alla creazione del componente, prima che le
 * traduzioni fossero caricate, e il valore grezzo restava lì. Nel template la stessa chiamata
 * funziona perché il template si rivaluta; qui serve dirlo. Difetto presente dalla 1.9.1-beta.8.
 */
const pageGuides = computed(() => [
  {
    title: trans('users.guides.users_title'),
    description: trans('users.guides.users_desc'),
    icon: Users,
    colorVariant: 'blue' as const
  },
  {
    title: trans('users.guides.roles_title'),
    description: trans('users.guides.roles_desc'),
    icon: ShieldCheck,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('users.guides.invites_title'),
    description: trans('users.guides.invites_desc'),
    icon: Mail,
    colorVariant: 'amber' as const
  }
]);

</script>

<template>
    <div class="px-4 py-6 space-y-6">
        
        <PageHeaderGuide
            :page-title="props.pageTitle || trans('users.layout.heading_title')" 
            :page-subtitle="props.pageSubtitle || trans('users.layout.heading_description')"
            :guides="pageGuides"
            :breadcrumbs="props.breadcrumbs || []"
            back-url="/impostazioni"
            :back-text="trans('impostazioni.label.settings')"
            :video-url="null"
            has-text-guide
            :text-guide-title="trans('users.guides.button')"
            @open-text-guide="mostraGuida = true"
        />

        <!--
            La barra sta **in alto** e non a sinistra, come nella sezione movimenti del gestionale.
            Il criterio non è estetico: la barra va in alto quando il contenuto della sezione ha
            bisogno di **larghezza**. Qui sotto ci sono quattro elenchi, e quello degli utenti ha
            sette colonne — con la barra a fianco, che si prende 12rem fisse, l'ultima usciva dallo
            schermo su un portatile.

            Le impostazioni personali (`layouts/settings/Layout.vue`) tengono la barra a sinistra, e
            non è un'incoerenza: lì il contenuto sono moduli stretti, e la larghezza non serve.

            `flex-wrap` + `max-w-full` sono ripresi dal gemello dei movimenti, dove sono nati
            quando la sesta voce ha fatto sforare la barra: con quattro ci sta comoda, ma la
            quinta non deve rompere niente.
        -->
        <div class="space-y-4">
            <NavSezione :items="vociNav" />

            <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
                <section class="w-full">
                    <slot />
                </section>
            </div>
        </div>
    </div>
  <UtentiGuide v-model:open="mostraGuida" />
</template>
