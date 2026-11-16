<?php

namespace App\Services\Knowledge;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads a client's website: the pages listed in its sitemap(s), then the links found on the pages, same host only,
 * up to $maxPages. Returns each page's title and readable text (menus, scripts, footers left out).
 */
class WebsiteCrawler
{
    private const SKIP_EXTENSIONS = '/\.(jpe?g|png|gif|webp|svg|ico|css|js|json|zip|rar|mp4|mp3|avi|mov|woff2?|ttf|eot|exe|dmg|xml|pdf|docx?|xlsx?|pptx?)$/i';

    /** @return list<array{url: string, title: string, text: string}> */
    public function crawl(string $startUrl, int $maxPages): array
    {
        if (! SafeUrl::isAllowed($startUrl)) {
            throw new RuntimeException('This address cannot be read. Use the public address of the website (http or https).');
        }
        $host = $this->host($startUrl);
        $origin = parse_url($startUrl, PHP_URL_SCHEME).'://'.parse_url($startUrl, PHP_URL_HOST).(parse_url($startUrl, PHP_URL_PORT) ? ':'.parse_url($startUrl, PHP_URL_PORT) : '');

        $queue = [];
        $pdfs = [];
        $seen = [];
        $add = function (string $url) use (&$queue, &$pdfs, &$seen, $host) {
            $url = $this->normalise($url);
            if (! $url || isset($seen[$url]) || $this->host($url) !== $host) {
                return;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            $seen[$url] = true;
            if (preg_match('/\.pdf$/i', $path)) {
                // PDFs the site links to (brochures, syllabi, price lists) are read after the pages
                $pdfs[] = $url;
            } elseif (! preg_match(self::SKIP_EXTENSIONS, $path)) {
                $queue[] = $url;
            }
        };

        $add($startUrl);
        $sitemap = str_ends_with(strtolower((string) parse_url($startUrl, PHP_URL_PATH)), '.xml') ? $startUrl : $origin.'/sitemap.xml';
        foreach ($this->sitemapUrls($sitemap, $maxPages * 2) as $url) {
            $add($url);
        }

        $pages = [];
        while ($queue !== [] && count($pages) < $maxPages) {
            $url = array_shift($queue);
            $response = $this->get($url);
            if (! $response || ! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'html')) {
                continue;
            }
            [$title, $text, $links] = $this->read($response->body(), $url);
            if (mb_strlen($text) >= 40) {
                $pages[] = ['url' => $url, 'title' => $title, 'text' => $text];
            }
            foreach ($links as $link) {
                $add($link);
            }
        }

        if ($pages === []) {
            throw new RuntimeException('No readable pages were found at this address.');
        }

        foreach (array_slice($pdfs, 0, (int) config('chat.crawl.max_pdfs', 20)) as $url) {
            if ($pdf = $this->readPdf($url)) {
                $pages[] = $pdf;
            }
        }

        return $pages;
    }

    /** @return array{url: string, title: string, text: string}|null a linked PDF's text, or null when it has none */
    private function readPdf(string $url): ?array
    {
        $response = $this->get($url);
        if (! $response || ! $response->successful() || strlen($response->body()) > (int) config('chat.crawl.max_pdf_bytes', 10 * 1024 * 1024)
            || ! str_starts_with($response->body(), '%PDF')) {
            return null;
        }
        try {
            $text = (new Parser)->parseContent($response->body())->getText();
        } catch (Throwable) {
            return null;
        }
        $text = trim(preg_replace("/[ \t]+/u", ' ', mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1')));
        if (mb_strlen($text) < 40) {
            return null;
        }
        $name = urldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return ['url' => $url, 'title' => mb_substr(preg_replace('/[-_]+/', ' ', preg_replace('/\.pdf$/i', '', $name)), 0, 250), 'text' => $text];
    }

    /** @return list<string> page URLs of a sitemap (or of the sitemaps of a sitemap index, one level down) */
    private function sitemapUrls(string $url, int $max, int $depth = 0): array
    {
        $response = $this->get($url);
        if (! $response || ! $response->successful() || ! str_contains($response->body(), '<loc>')) {
            return [];
        }
        preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $response->body(), $m);
        $locs = array_map(fn ($l) => html_entity_decode(trim($l)), $m[1]);

        if (str_contains($response->body(), '<sitemapindex') && $depth === 0) {
            $urls = [];
            foreach (array_slice($locs, 0, 10) as $child) {
                $urls = array_merge($urls, $this->sitemapUrls($child, $max - count($urls), 1));
                if (count($urls) >= $max) {
                    break;
                }
            }

            return array_slice($urls, 0, $max);
        }

        return array_slice($locs, 0, $max);
    }

    private function get(string $url): ?Response
    {
        if (! SafeUrl::isAllowed($url)) {
            return null;
        }
        try {
            return Http::withHeaders(['User-Agent' => (string) config('chat.crawl.user_agent'), 'Accept-Language' => 'ar,en;q=0.8'])
                ->timeout((int) config('chat.crawl.timeout'))
                ->withOptions(['allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['http', 'https'],
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
                        if (! SafeUrl::isAllowed((string) $uri)) {
                            throw new RuntimeException('Redirect to a blocked address');
                        }
                    },
                ]])
                ->get($url);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: string, 2: list<string>} title, text, links */
    public function read(string $html, string $url): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $h1 = trim((string) ($xpath->query('//h1')->item(0)?->textContent ?? ''));
        $title = mb_substr(preg_replace('/\s+/u', ' ', $title ?: $h1 ?: $url), 0, 250);

        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href !== '' && ! preg_match('/^(mailto:|tel:|javascript:|#|whatsapp:)/i', $href)) {
                $links[] = $this->absolute($href, $url);
            }
        }

        foreach (['script', 'style', 'noscript', 'svg', 'nav', 'header', 'footer', 'form', 'iframe', 'template'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach ($xpath->query('//*[@aria-hidden="true" or contains(@class,"cookie") or @role="navigation"]') as $node) {
            $node->parentNode?->removeChild($node);
        }
        // A line break after every block, so words of different blocks don't run together
        foreach ($xpath->query('//p|//div|//li|//h1|//h2|//h3|//h4|//h5|//h6|//tr|//br|//section|//article|//dd|//dt|//td|//th') as $node) {
            $node->appendChild($dom->createTextNode("\n"));
        }

        $main = $xpath->query('//main')->item(0) ?? $dom->getElementsByTagName('body')->item(0);
        $raw = $main ? $main->textContent : '';
        $lines = array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $raw)), fn ($l) => $l !== '');
        $text = implode("\n", array_values($lines));

        return [$title, $text, array_values(array_filter($links))];
    }

    private function absolute(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $root = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return $parts['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $root.$href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $root.($dir ?: '/').$href;
    }

    /** Without the #fragment and tracking parameters; null for what is not a web address. */
    private function normalise(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = preg_replace('/#.*$/', '', trim($url));
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_filter($params, fn ($k) => ! preg_match('/^(utm_|fbclid|gclid|ref$)/i', (string) $k), ARRAY_FILTER_USE_KEY);
            $query = $params ? '?'.http_build_query($params) : '';
        }
        $path = $parts['path'] ?? '/';

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($path === '' ? '/' : $path).$query;
    }

    private function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }
}
