<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuGroup,
  DropdownMenuItem
} from '@/components/ui/dropdown-menu';
// Si tienes un componente Label, asegúrate de importarlo:
import { Label } from '@/components/ui/label';

const { t, locale } = useI18n();

const availableLocales = ['en', 'es'];

const currentLocale = ref(locale.value);

// languageMap se recalcula cuando cambia el locale
const languageMap = computed(() => ({
  en: t('settings.english') || 'English',
  es: t('settings.spanish') || 'Español'
}));

const setLanguage = (lang: string) => {
  currentLocale.value = lang;
  locale.value = lang;
  localStorage.setItem('locale', lang);
};

onMounted(() => {
  const savedLocale = localStorage.getItem('locale');
  if (savedLocale && availableLocales.includes(savedLocale)) {
    currentLocale.value = savedLocale;
    locale.value = savedLocale;
  }
});
</script>

<template>
  <div>
    <!-- Label agregado para "Idioma" -->
    <Label for="language" class="mr-10">
      {{ t('settings.language') || 'Idioma' }}
    </Label>

    <DropdownMenu>
      <DropdownMenuTrigger :as-child="true">
        <Button
          variant="ghost"
          size="icon"
          class="relative size-10 w-24 rounded-full p-1 focus-within:ring-2 focus-within:ring-primary mt-0"
        >
          <span class="text-xs font-medium">
            {{ languageMap[currentLocale] || currentLocale.toUpperCase() }}
          </span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-56">
        <DropdownMenuLabel class="p-0 font-normal">
          <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            {{ t('settings.select_lang') || 'Selecciona tu idioma' }}
          </div>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
          <DropdownMenuItem v-for="lang in availableLocales" :key="lang" :as-child="true">
            <button
              class="block w-full text-left px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700"
              @click="setLanguage(lang)"
            >
              {{ languageMap[lang] }}
            </button>
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
