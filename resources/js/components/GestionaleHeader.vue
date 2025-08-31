<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { NavigationMenu, NavigationMenuItem, NavigationMenuLink, NavigationMenuList, navigationMenuTriggerStyle } from '@/components/ui/navigation-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { Settings, GitGraph, HousePlus, Building as BuildingIcon, BookText, LayoutGrid, Menu } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { BreadcrumbItem, NavItem, Auth} from '@/types';
import type { Building } from '@/types/buildings';

const { generatePath, canAccess } = usePermission();

interface Props {
    breadcrumbs?: BreadcrumbItem[];
}

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed<Auth>(() => page.props.auth as Auth);
const condominio = computed<Building>(() => page.props.condominio as Building);

const isCurrentRoute = (url: string) => {
    return page.url === url;
};

const activeItemStyles = computed(() => (url: string) => (isCurrentRoute(url) ? 'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100' : ''));

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: generatePath('gestionale/:condominio', { condominio: condominio.value.id }),
        icon: LayoutGrid
    },
    {
        title: 'Condominio',
        href: generatePath('gestionale/:condominio/struttura', { condominio: condominio.value.id }),
        icon: BuildingIcon
    },
    {
        title: 'Immobili',
        href: generatePath('gestionale/:condominio/immobili', { condominio: condominio.value.id }),
        icon: HousePlus,
       
    } 
];

const rightNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/vince844/kondomanager-free',
        icon: GitGraph,
        external: true,
    },
    {
        title: 'Documentazione',
        href: 'https://kondomanager-1.gitbook.io/kondomanager-docs',
        icon: BookText,
        external: true,
    },
    {
        title: 'Impostazioni',
        href: '/impostazioni',
        icon: Settings,
        external: false,
    },
];

</script>

<template>
    <div>
        <div class="border-b border-sidebar-border/80">
            <div class="mx-auto flex h-16 items-center px-4 md:max-w-7xl">
                <!-- Mobile Menu -->
                <div class="lg:hidden">
                    <Sheet>
                        <SheetTrigger :as-child="true">
                            <Button variant="ghost" size="icon" class="mr-2 h-9 w-9">
                                <Menu class="h-5 w-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" class="w-[300px] p-6">
                            <SheetTitle class="sr-only">Navigation Menu</SheetTitle>
                            <SheetHeader class="flex justify-start text-left">
                                <AppLogoIcon class="size-6 fill-current text-black dark:text-white" />
                            </SheetHeader>
                            <div class="flex h-full flex-1 flex-col justify-start gap-4 py-6">
                                <!-- Main Navigation -->
                                <nav
                                v-for="(item, index) in mainNavItems.filter(item => canAccess(item))"
                                :key="index"
                                class="space-y-1"
                                >
                                    <Link
                                    :href="item.href"
                                    class="flex items-center gap-3 rounded-lg px-3 py-1 text-sm font-medium hover:bg-accent"
                                    :class="activeItemStyles(item.href)"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="h-5 w-5" />
                                        {{ item.title }}
                                    </Link>
                                </nav>

                                <!-- Right Navigation -->
                                <div class="flex flex-col gap-4 px-3">
                                    <template v-for="item in rightNavItems" :key="item.title">
                                        <!-- External -->
                                        <a
                                            v-if="item.external"
                                            :href="item.href"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="flex items-center gap-2 text-sm font-medium py-1"
                                        >
                                            <component v-if="item.icon" :is="item.icon" class="h-5 w-5" />
                                            <span>{{ item.title }}</span>
                                        </a>

                                        <!-- Internal -->
                                        <Link
                                            v-else
                                            :href="item.href"
                                            class="flex items-center gap-2 text-sm font-medium py-1"
                                        >
                                            <component v-if="item.icon" :is="item.icon" class="h-5 w-5" />
                                            <span>{{ item.title }}</span>
                                        </Link>
                                    </template>
                                </div>
                            </div>

                        </SheetContent>
                    </Sheet>
                </div>

                <Link :href="generatePath('dashboard')" class="flex items-center gap-x-2">
                    <AppLogo class="hidden h-6 xl:block" />
                </Link>

                <!-- Desktop Menu -->
                <div class="hidden h-full lg:flex lg:flex-1">
                    <NavigationMenu class="ml-6 flex h-full items-stretch">
                        <NavigationMenuList class="flex h-full items-stretch space-x-1">
                            <NavigationMenuItem v-for="(item, index) in mainNavItems" :key="index" class="relative flex h-full items-center">

                                <Link :href="item.href" v-if="canAccess(item)"> 
                                    <NavigationMenuLink
                                        :class="[navigationMenuTriggerStyle(), activeItemStyles(item.href), 'h-9 cursor-pointer px-3']"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="mr-2 h-4 w-4" />
                                        {{ item.title }}
                                    </NavigationMenuLink>
                                </Link>
                                <div
                                    v-if="isCurrentRoute(item.href)"
                                    class="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"
                                ></div>
                              
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>

                <div class="ml-auto flex items-center space-x-2">
                    <div class="relative flex items-center space-x-1">

                        <div class="hidden space-x-1 lg:flex">
                            <template v-for="item in rightNavItems" :key="item.title">
                                <TooltipProvider :delay-duration="0">
                                <Tooltip>
                                    <TooltipTrigger>
                                    <Button variant="ghost" size="icon" as-child class="group h-9 w-9 cursor-pointer">
                                        <!-- External links -->
                                        <a
                                            v-if="item.external"
                                            :href="item.href"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <span class="sr-only">{{ item.title }}</span>
                                            <component :is="item.icon" class="size-5 opacity-80 group-hover:opacity-100" />
                                        </a>

                                        <!-- Internal links -->
                                        <Link v-else :href="item.href">
                                            <span class="sr-only">{{ item.title }}</span>
                                            <component :is="item.icon" class="size-5 opacity-80 group-hover:opacity-100" />
                                        </Link>
                                    </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                    <p>{{ item.title }}</p>
                                    </TooltipContent>
                                </Tooltip>
                                </TooltipProvider>
                            </template>
                        </div>
                        
                    </div>

                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-10 w-auto rounded-full p-1 focus-within:ring-2 focus-within:ring-primary"
                            >
                                <Avatar class="size-8 overflow-hidden rounded-full">
                                    <AvatarImage v-if="auth.user.avatar" :src="auth.user.avatar" :alt="auth.user.name" />
                                    <AvatarFallback class="rounded-lg bg-neutral-200 font-semibold text-black dark:bg-neutral-700 dark:text-white">
                                        {{ getInitials(auth.user?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div v-if="props.breadcrumbs.length > 1" class="flex w-full border-b border-sidebar-border/70">
            <div class="mx-auto flex h-12 w-full items-center justify-start px-4 text-neutral-500 md:max-w-7xl">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
