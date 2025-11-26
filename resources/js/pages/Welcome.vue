<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
  <Head title="Welcome">
    <link rel="preconnect" href="https://rsms.me/" />
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
  </Head>

  <div class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] lg:justify-center lg:p-8">
    <!-- Header con Accedi / Registrati / Dashboard -->
    <header class="not-has-[nav]:hidden mb-6 w-full max-w-[335px] text-sm lg:max-w-4xl">
      <nav class="flex items-center justify-end gap-4">
        <template v-if="$page.props.auth?.user">
          <Link
            v-if="$page.props.auth?.user?.roles?.includes('amministratore')"
            :href="route('admin.dashboard')"
            class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
          >
            Admin Dashboard
          </Link>
          <Link
            v-else
            :href="route('user.dashboard')"
            class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
          >
            Dashboard
          </Link>
        </template>

        <template v-else>
          <Link
            :href="route('login')"
            class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
          >
            Accedi
          </Link>
          <Link
            v-if="$page.props.can_register"
            :href="route('register')"
            class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
          >
            Registrati
          </Link>
        </template>
      </nav>
    </header>

    <!-- Contenuto principale -->
    <div class="duration-750 starting:opacity-0 flex w-full items-center justify-center opacity-100 transition-opacity lg:grow">
      <main class="flex w-full max-w-[335px] flex-col-reverse overflow-hidden rounded-lg lg:max-w-4xl lg:flex-row">
        
        <!-- Testo a sinistra -->
        <div class="flex-1 rounded-bl-lg rounded-br-lg bg-white p-6 pb-12 text-[13px] leading-[20px] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:bg-[#161615] dark:text-[#EDEDEC] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] lg:rounded-br-none lg:rounded-tl-lg lg:p-20">
          <h1 class="mb-1 font-medium text-lg">Per iniziare</h1>
          <p class="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
            KondoManager è un software di gestione condominiale open source gratuito, progettato per semplificare l'amministrazione dei condomini.
          </p>

          <ul class="mb-6 space-y-4">
            <li class="flex items-start gap-4">
              <span class="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]">
                <span class="h-2 w-2 rounded-full bg-[#dbdbd7] dark:bg-[#3E3E3A]" />
              </span>
              <span>
                Leggi la
                <a href="https://www.kondomanager.com/docs" target="_blank" class="font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]">
                  Documentazione <span class="ml-1 inline-block">↗</span>
                </a>
              </span>
            </li>
            <li class="flex items-start gap-4">
              <span class="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]">
                <span class="h-2 w-2 rounded-full bg-[#dbdbd7] dark:bg-[#3E3E3A]" />
              </span>
              <span>
                Guarda i tutorial su
                <a href="https://www.youtube.com/@Kondomanager" target="_blank" class="font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]">
                  YouTube <span class="ml-1 inline-block">↗</span>
                </a>
              </span>
            </li>
          </ul>

          <div class="flex gap-3">
            <Link
              :href="route('login')"
              class="inline-block rounded-sm border border-black bg-[#1b1b18] px-6 py-2.5 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
              Accedi al portale
            </Link>
          </div>
        </div>

        <!-- Logo a destra (o sopra su mobile) -->
        <div class="relative -mb-px aspect-[335/376] w-full shrink-0 overflow-hidden rounded-t-lg bg-transparent lg:-ml-px lg:mb-0 lg:aspect-auto lg:w-[438px] lg:rounded-r-lg lg:rounded-t-none">
          <img
            src="../../../public/2667867.png"
            alt="KondoManager"
            class="w-full max-w-none object-contain drop-shadow-xl"
          />
          <!-- Bordo sottile come nell'originale -->
          <div class="pointer-events-none absolute inset-0 rounded-t-lg shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_rgba(255,255,255,0.08)] lg:rounded-r-lg lg:rounded-t-none" />
        </div>
      </main>
    </div>

    <div class="h-14.5 hidden lg:block"></div>
  </div>
</template>