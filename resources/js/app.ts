import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import { initializeTheme } from './composables/useAppearance';
import { createI18n } from 'vue-i18n';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
  interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
    [key: string]: string | boolean | undefined;
  }
  interface ImportMeta {
    readonly env: ImportMetaEnv;
  }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Usar import.meta.glob con la opción eager para cargar los archivos JSON de traducción
const modules = import.meta.glob('./locales/**/!(*.d).json', { eager: true });
const messages: Record<string, any> = {};

for (const path in modules) {
  // Cambiamos la expresión regular para buscar en "locale"
  const matches = path.match(/\/locales\/([^\/]+)\//);
  if (matches && matches[1]) {
    const locale = matches[1];
    if (!messages[locale]) {
      messages[locale] = {};
    }
    // Fusionar el contenido del archivo en el objeto de traducciones para el idioma
    Object.assign(messages[locale], (modules[path] as any).default);
  }

}
console.log(modules);

const i18n = createI18n({
  legacy: false,       // Usar Composition API
  locale: 'en',        // Idioma por defecto
  fallbackLocale: 'en',// Idioma de respaldo
  messages,
});

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) =>
    resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(ZiggyVue)
      .use(i18n) // Instala vue-i18n
      .mount(el);
  },
  progress: {
    color: '#4B5563',
  },
});

// Esto configurará el tema (light / dark) al cargar la página
initializeTheme();
