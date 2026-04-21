<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use XMLReader;

class RelationUrls extends Command
{
    protected $signature = 'app:relation-urls {--categorias : Relaciona URLs antiguas de categorías contra categorias_nuevas.txt}';

    protected $description = 'Relaciona URLs antiguas con nuevas URLs de productos o categorías y genera CSVs de salida.';

    public function handle(): int
    {
        $marker = '[RELATION_URLS v1]';
        $baseDir = public_path('redirects');
        $categoriesPath = $baseDir . '/categorias_nuevas.txt';
        $isCategoriesMode = (bool) $this->option('categorias');
        $oldCsvPath = $baseDir . ($isCategoriesMode ? '/url-old-categorias-ferrate.csv' : '/url-old-productos-ferrate.csv');
        $productsXmlPath = $baseDir . '/nuevos_enlaces_productos.xml';
        $timestamp = now()->format('Ymd_His');
        $relatedPath = $baseDir . ($isCategoriesMode ? '/categorias_related_' . $timestamp . '.csv' : '/related_' . $timestamp . '.csv');
        $notRelatedPath = $baseDir . ($isCategoriesMode ? '/categorias_not_related.csv' : '/not_related.csv');

        $this->line($marker . ' start');
        Log::info($marker . ' start', [
            'base_dir' => $baseDir,
            'mode' => $isCategoriesMode ? 'categorias' : 'productos',
        ]);

        $requiredPaths = [$oldCsvPath, $categoriesPath];
        if (!$isCategoriesMode) {
            $requiredPaths[] = $productsXmlPath;
        }

        foreach ($requiredPaths as $path) {
            if (!is_file($path)) {
                $this->error('No existe el archivo requerido: ' . $path);
                Log::error($marker . ' missing file', ['path' => $path]);
                return self::FAILURE;
            }
        }

        try {
            $this->info('Cargando categorías nuevas...');
            $categories = $this->loadCategories($categoriesPath);
            $productUrlsBySlug = [];

            if (!$isCategoriesMode) {
                $this->info('Cargando productos nuevos desde XML...');
                $productUrlsBySlug = $this->loadProductUrlsBySlug($productsXmlPath);
            }

            $this->info('Leyendo URLs antiguas y generando relaciones...');

            $relatedHandle = fopen($relatedPath, 'w');
            $notRelatedHandle = fopen($notRelatedPath, 'w');

            if ($relatedHandle === false || $notRelatedHandle === false) {
                throw new \RuntimeException('No se pudieron abrir los CSV de salida');
            }

            try {
                fputcsv($relatedHandle, ['URL ANTIGUA', 'URL NUEVA']);
                fputcsv($notRelatedHandle, ['URL ANTIGUA']);

                $oldUrls = $this->loadOldUrls($oldCsvPath);

                $related = 0;
                $notRelated = 0;
                $processed = 0;

                foreach ($oldUrls as $oldUrl) {
                    $processed++;
                    $newUrl = $isCategoriesMode
                        ? $this->resolveCategoryUrl($oldUrl, $categories)
                        : $this->resolveNewUrl($oldUrl, $productUrlsBySlug, $categories);

                    if ($newUrl !== null) {
                        fputcsv($relatedHandle, [$oldUrl, $newUrl]);
                        $related++;
                    } else {
                        fputcsv($notRelatedHandle, [$oldUrl]);
                        $notRelated++;
                    }

                    if (($processed % 1000) === 0) {
                        $this->line("Procesadas {$processed} URLs | relacionadas={$related} | sin_relacion={$notRelated}");
                    }
                }
            } finally {
                fclose($relatedHandle);
                fclose($notRelatedHandle);
            }
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error($marker . ' failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->info("URLs relacionadas: {$related}");
        $this->info("URLs sin relacionar: {$notRelated}");
        $this->info("CSV generado: {$relatedPath}");
        $this->info("CSV generado: {$notRelatedPath}");

        Log::info($marker . ' done', [
            'related' => $related,
            'not_related' => $notRelated,
            'related_path' => $relatedPath,
            'not_related_path' => $notRelatedPath,
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function loadOldUrls(string $path): array
    {
        $urls = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el CSV de URLs antiguas');
        }

        try {
            $headerSkipped = false;
            while (($row = fgetcsv($handle)) !== false) {
                if (!$headerSkipped) {
                    $headerSkipped = true;
                    continue;
                }

                $url = trim((string) ($row[0] ?? ''));
                if ($url === '') {
                    continue;
                }
                $urls[] = $url;
            }
        } finally {
            fclose($handle);
        }

        return $urls;
    }

    /**
     * @return array<int,array{url:string,tokens:array<int,string>,depth:int}>
     */
    private function loadCategories(string $path): array
    {
        $categories = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('No se pudo leer el TXT de categorías');
        }

        foreach ($lines as $line) {
            $url = trim((string) $line);
            if ($url === '') {
                continue;
            }

            $pathPart = (string) parse_url($url, PHP_URL_PATH);
            $trimmed = trim($pathPart, '/');
            $segments = $trimmed === '' ? [] : explode('/', $trimmed);
            $tokens = [];
            foreach ($segments as $segment) {
                $tokens = array_merge($tokens, $this->slugToTokens($segment));
            }

            $categories[] = [
                'url' => $url,
                'tokens' => array_values(array_unique($tokens)),
                'depth' => count($segments),
            ];
        }

        return $categories;
    }

    /**
     * @return array<string,string>
     */
    private function loadProductUrlsBySlug(string $path): array
    {
        $reader = new XMLReader();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException('No se pudo abrir el XML de productos');
        }

        $map = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'item') {
                    continue;
                }

                $itemXml = $reader->readOuterXML();
                if (!is_string($itemXml) || $itemXml === '') {
                    continue;
                }

                $item = @simplexml_load_string($itemXml);
                if ($item === false) {
                    continue;
                }

                $namespaces = $item->getNamespaces(true);
                $wp = isset($namespaces['wp']) ? $item->children($namespaces['wp']) : null;
                $postType = $wp ? trim((string) $wp->post_type) : '';
                $status = $wp ? trim((string) $wp->status) : '';

                if ($postType !== 'product' || ($status !== '' && $status !== 'publish')) {
                    continue;
                }

                $link = trim((string) $item->link);
                if ($link === '') {
                    continue;
                }

                $slug = $this->extractNormalizedSlug($link);
                if ($slug === '') {
                    continue;
                }

                if (!isset($map[$slug])) {
                    $map[$slug] = $link;
                }
            }
        } finally {
            $reader->close();
        }

        return $map;
    }

    /**
     * @param array<string,string> $productUrlsBySlug
     * @param array<int,array{url:string,tokens:array<int,string>,depth:int}> $categories
     */
    private function resolveNewUrl(string $oldUrl, array $productUrlsBySlug, array $categories): ?string
    {
        $slug = $this->extractNormalizedSlug($oldUrl);
        if ($slug !== '' && isset($productUrlsBySlug[$slug])) {
            return $productUrlsBySlug[$slug];
        }

        $tokens = $this->slugToTokens($slug);
        if (empty($tokens)) {
            return null;
        }

        $bestUrl = null;
        $bestScore = 0;
        $bestDepth = -1;

        foreach ($categories as $category) {
            $score = count(array_intersect($tokens, $category['tokens']));
            if ($score <= 0) {
                continue;
            }

            if ($score > $bestScore || ($score === $bestScore && $category['depth'] > $bestDepth)) {
                $bestScore = $score;
                $bestDepth = $category['depth'];
                $bestUrl = $category['url'];
            }
        }

        return $bestUrl;
    }

    /**
     * @param array<int,array{url:string,tokens:array<int,string>,depth:int}> $categories
     */
    private function resolveCategoryUrl(string $oldUrl, array $categories): ?string
    {
        $slug = $this->extractNormalizedSlug($oldUrl);
        if ($slug === '') {
            return null;
        }

        $exactSlugMatch = null;
        foreach ($categories as $category) {
            if ($this->extractNormalizedSlug($category['url']) === $slug) {
                $exactSlugMatch = $category['url'];
                break;
            }
        }

        if ($exactSlugMatch !== null) {
            return $exactSlugMatch;
        }

        $tokens = $this->slugToTokens($slug);
        if (empty($tokens)) {
            return null;
        }

        $bestUrl = null;
        $bestScore = 0;
        $bestDepth = -1;

        foreach ($categories as $category) {
            $score = count(array_intersect($tokens, $category['tokens']));
            if ($score <= 0) {
                continue;
            }

            if ($score > $bestScore || ($score === $bestScore && $category['depth'] > $bestDepth)) {
                $bestScore = $score;
                $bestDepth = $category['depth'];
                $bestUrl = $category['url'];
            }
        }

        return $bestUrl;
    }

    private function extractNormalizedSlug(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $last = trim((string) end($segments));
        if ($last === '') {
            return '';
        }

        $last = preg_replace('/^\d+-/u', '', $last) ?? $last;
        return $this->normalizeSlug($last);
    }

    private function normalizeSlug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value);
        $value = str_replace(['á', 'à', 'ä', 'â', 'ã'], 'a', $value);
        $value = str_replace(['é', 'è', 'ë', 'ê'], 'e', $value);
        $value = str_replace(['í', 'ì', 'ï', 'î'], 'i', $value);
        $value = str_replace(['ó', 'ò', 'ö', 'ô', 'õ'], 'o', $value);
        $value = str_replace(['ú', 'ù', 'ü', 'û'], 'u', $value);
        $value = str_replace(['ñ'], 'n', $value);
        $value = preg_replace('/[^a-z0-9\-\/]+/u', '-', $value) ?? '';
        $value = preg_replace('/-+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * @return array<int,string>
     */
    private function slugToTokens(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return [];
        }

        $rawTokens = preg_split('/[-\/]+/u', $slug) ?: [];
        $stopwords = [
            'de', 'del', 'la', 'el', 'los', 'las', 'y', 'en', 'con', 'por', 'para',
            'un', 'una', 'uno', 'unas', 'unos', 'a', 'o', 'u',
        ];

        $tokens = [];
        foreach ($rawTokens as $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, $stopwords, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }
}
