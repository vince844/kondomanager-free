<script setup lang="ts">

import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';
import { UsersRound, Drama, KeyRound, Mails } from 'lucide-vue-next';
import type { LinkItem } from '@/types';

const sidebarNavItems: LinkItem[] = [
    {
        type: 'link',
        icon: UsersRound,
        title: 'Utenti',
        href: '/utenti',
    },
    {
        type: 'link',
        icon: Drama,
        title: 'Ruoli',
        href: '/ruoli',
    },
    {
        type: 'link',
        icon: KeyRound,
        title: 'Permessi',
        href: '/permessi',
    },
    {
        type: 'link',
        icon: Mails,
        title: 'Inviti',
        href: '/inviti',
    }
];

const currentPath = window.location.pathname;

</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Gestione utenti" description="Di seguito un elenco degli utenti registrati, ruoli, permessi e inviti" />

        <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-5 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-x-0 space-y-1 shadow ring-1 ring-black/5 md:rounded-lg p-2">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': currentPath.startsWith(item.href) }]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component v-if="item.icon" :is="item.icon" class="mr-1 h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>
            
            <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
                <section class="w-full">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
