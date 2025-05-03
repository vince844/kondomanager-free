<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Utenti',
        href: '/utenti',
    },
    {
        title: 'Ruoli',
        href: '/ruoli',
    },
    {
        title: 'Permessi',
        href: '/permessi',
    },
    {
        title: 'Inviti',
        href: '/inviti',
    }
];

const currentPath = window.location.pathname;

</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Utenti registrati" description="Gestione degli utenti registrati" />

        <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-5 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-x-0 space-y-1 shadow ring-1 ring-black ring-opacity-5 md:rounded-lg p-2">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': currentPath.startsWith(item.href) }]"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>
            
            <div class="w-full">
                <section class="w-full">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
