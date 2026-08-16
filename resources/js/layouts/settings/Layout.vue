<script setup lang="ts">

import Heading from '@/components/Heading.vue';
import NavSezione from '@/components/NavSezione.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Link } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";
import { ScanFace, KeyRound, BellRing, UserRound, MonitorSmartphone } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{ contentClass?: string }>();
const contentClass = props.contentClass || 'max-w-xl';

const { generatePath } = usePermission();

const sidebarNavItems = computed<LinkItem[]>(() => [
    {
        type: 'link',
        icon: UserRound,
        title: trans('settings.layout.nav.profile'),
        href: '/settings/profile',
    },
    {
        type: 'link',
        icon: KeyRound,
        title: trans('settings.layout.nav.password'),
        href: '/settings/password',
    },
    {
        type: 'link',
        icon: ScanFace,
        title: trans('settings.layout.nav.two_factor'),
        href: '/settings/two-factor',
    },
    {
        type: 'link',
        icon: BellRing,
        title: trans('settings.layout.nav.notifications'),
        href: generatePath('settings/notifications'),
    },
    {
        type: 'link',
        icon: MonitorSmartphone,
        title: trans('settings.layout.nav.appearance'),
        href: '/settings/appearance',
    }
]);

const currentPath = window.location.pathname;

</script>

<template>
    <div class="px-4 py-6">
        <Heading :title="trans('settings.layout.title')" :description="trans('settings.layout.description')" />

        <!--
            Barra in alto come nel resto del programma, deciso il 16/08/2026: qui il contenuto
            sono moduli stretti e la larghezza non servirebbe, ma **l'uniformità sì**. Con la barra
            a sinistra queste cinque pagine erano le uniche a navigare in un modo tutto loro, e chi
            arriva dalle impostazioni del condominio doveva cambiare gesto per fare la stessa cosa.

            Il modulo resta stretto — `contentClass`, `max-w-xl` di default — perché un campo largo
            quanto lo schermo si legge peggio: cambia la navigazione, non la misura del contenuto.
        -->
        <div class="space-y-4">
            <NavSezione :items="sidebarNavItems" :esatte="true" />

            <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
                <section :class="contentClass">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
