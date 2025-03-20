// resources/js/i18n.js
import { createI18n } from 'vue-i18n';

// Cargar automáticamente todos los archivos JSON de traducciones de cada idioma
const modules = import.meta.globEager('./locales/**/!(*.d).json'); // Carga todos los JSON en subdirectorios
const messages = {};

// Se espera que la ruta sea algo como "./locales/en/common.json"
for (const path in modules) {
  // Extraer el código de idioma (por ejemplo, "en" o "es") de la ruta
  const matches = path.match(/\/locales\/([^\/]+)\//);
  if (matches && matches[1]) {
    const locale = matches[1];
    // Si aún no se ha creado la entrada para el idioma, la inicializamos
    if (!messages[locale]) {
      messages[locale] = {};
    }
    // Fusiona el contenido del archivo JSON en el objeto del idioma
    Object.assign(messages[locale], modules[path].default);
  }
}

const i18n = createI18n({
  legacy: false,       // Usar Composition API
  locale: 'es',        // Idioma por defecto
  fallbackLocale: 'en', // Idioma de respaldo
  messages,
});

export default i18n;
