<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";

const { generatePath } = usePermission();

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profilo',
        href: '/settings/profile',
    },
    {
        title: 'Password',
        href: '/settings/password',
    },
    {
        title: 'Notifiche',
        href: generatePath('settings/notifications'),
    },
    {
        title: 'Aspetto',
        href: '/settings/appearance',
    }
   
];

const currentPath = window.location.pathname;

</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Impostazioni" description="Gestione del tuo tuo profilo e impostazioni account" />

        <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-5 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-x-0 space-y-1 shadow ring-1 ring-black ring-opacity-5 md:rounded-lg p-2">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 md:hidden" />

            <div class="w-full shadow ring-1 ring-black ring-opacity-5 md:rounded-lg p-4">
                <section class="max-w-xl">
                    <slot />
                </section>
            </div> 
        </div>
    </div>
</template>
