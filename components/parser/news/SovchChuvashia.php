<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\TyRunBaseParser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей из RSS ленты http://sovch.chuvashia.com/
 *
 */
class SovchChuvashia extends TyRunBaseParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const MAIN_PAGE_URI = 'http://sovch.chuvashia.com';

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = 'article.post';

    const QUOTE_TAG = 'blockquote';

    /**
     * CSS класс, где хранится контент новости
     */
    const CONTENT_CSS_SELECTOR = '.entry-content';

    /**
     * Классы эоементов, которые не нужно парсить, например блоки с рекламой и т.п.
     * в формате RegExp
     */
    const EXCLUDE_CSS_CLASSES_PATTERN = '';

    /**
     * Класс элемента после которого парсить страницу не имеет смысла (контент статьи закончился)
     */
    const CUT_CSS_REGEXP = '';

    /**
     * Ссылка на RSS фид (XML)
     */
    const FEED_URL = 'http://sovch.chuvashia.com/?feed=rss2';

    /**
     *  Максимальная глубина для парсинга <div> тегов
     */
    const MAX_PARSE_DEPTH = 3;

    /**
     * Префикс для элементов списков (ul, ol и т.п.)
     * при преобразовании в текст
     * @see parseUl()
     */
    const UL_PREFIX = '-';

    /**
     * Кол-во новостей, которое необходимо парсить
     */
    const MAX_NEWS_COUNT = 3;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $rss = $curl->get(self::FEED_URL);

        $crawler = new Crawler($rss);
        $crawler->filter('rss channel item')->slice(0, self::MAX_NEWS_COUNT)->each(function (Crawler $node) use (&$curl, &$posts) {
            $newPost = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                self::prepareDescription($node->filter('description')->text(),
                    '/(.*)\.(.*)(\[&#8230;])(.*)/'),
                self::stringToDateTime($node->filter('pubDate')->text()),
                $node->filter('link')->text(),
                null
            );

            /**
             * Предложения содержащиеся в описании (для последующей проверки при парсинга тела новости)
             */
            $descriptionSentences = explode('. ', html_entity_decode($newPost->description));

            /**
             * Получаем полный html новости
             */
            $newsContent = $curl->get($newPost->original);
            if (!empty($newsContent)) {
                $newsContent = (new Crawler($newsContent))->filter(self::BODY_CONTAINER_CSS_SELECTOR);
                if (!$newsContent->count()) {
                    return;
                }

                $articleContent = $newsContent->filter(self::CONTENT_CSS_SELECTOR);
                if (!$articleContent->count()) {
                    return;
                }

                /**
                 * Основное фото
                 */
                $mainImage = $newsContent->filter('figure.post-thumbnail img');
                if ($mainImage->count()) {
                    $src = $mainImage->first()->attr('data-lazy-src');
                    $newPost->image = Helper::encodeUrl($src);
                }

                $articleContent = $articleContent->children();
                $stopParsing = false;
                if ($articleContent->count()) {
                    $articleContent->each(function (Crawler $node) use ($newPost, &$stopParsing, $descriptionSentences) {
                        if ($stopParsing) {
                            return;
                        }
                        self::parseNode($node, $newPost, self::MAX_PARSE_DEPTH, $stopParsing, $descriptionSentences);
                    });
                }
            }

            $posts[] = $newPost;
        });

        return $posts;
    }

    protected static function parseNode(Crawler $node, NewsPost $newPost, int $maxDepth, bool &$stopParsing, $descriptionSentences = []): void
    {
        /**
         * Пропускаем элемент, если элемент имеет определенный класс
         * @see EXCLUDE_CSS_CLASSES_PATTERN
         */
        if (self::EXCLUDE_CSS_CLASSES_PATTERN
            && preg_match(self::EXCLUDE_CSS_CLASSES_PATTERN, $node->attr('class'))) {
            return;
        }

        /**
         * Прекращаем парсить страницу, если дошли до конца статьи
         * (до определенного элемента с классом указанным в @see CUT_CSS_CLASS )
         *
         */
        $stringForCheck = $node->attr('id').' '.$node->attr('class');
        if (self::CUT_CSS_REGEXP && preg_match(self::CUT_CSS_REGEXP, $stringForCheck)) {
            $maxDepth = 0;
            $stopParsing = true;
        }

        /**
         * Ограничение максимальной глубины парсинга
         * @see MAX_PARSE_DEPTH
         */
        if (!$maxDepth) {
            return;
        }
        $maxDepth--;

        switch ($node->nodeName()) {
            case 'div':
                $nodes = $node->children();
                if ($nodes->count()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'p':
                self::parseDescriptionIntersectParagraph($node, $newPost, $descriptionSentences);
                if ($nodes = $node->children()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case self::QUOTE_TAG:
                self::parseQuote($node, $newPost);
                break;
            case 'img':
                self::parseImage($node, $newPost, 'data-lazy-src');
                break;
            case 'video':
                $videoId = self::extractYouTubeId($node->filter('source')->first()->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'a':
                self::parseLink($node, $newPost);
                break;
            case 'iframe':
                $videoId = self::extractYouTubeId($node->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'ul':
            case 'ol':
                self::parseUl($node, $newPost);
                break;
        }

    }

    protected static function getProperImageSrc(Crawler $node, string $lazySrcAttr): ?string
    {
        $src = $node->attr($lazySrcAttr) ?? $node->attr('src');
        $src = self::absoluteUrl($src);
        return $src ? self::urlEncode($src) : false;
    }

    protected static function parseQuote(Crawler $node, NewsPost $newPost): void
    {
        if (!empty($node->text())) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_QUOTE,
                    $node->text(),
                    null,
                    null,
                    null,
                    null
                ));
        }
    }

    protected static function parseLink(Crawler $node, NewsPost $newPost): void
    {
        if (filter_var($node->attr('href'), FILTER_VALIDATE_URL)
            && !stristr($node->attr('class'), 'link-more')) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_LINK,
                    null,
                    null,
                    $node->attr('href'),
                    null,
                    null
                ));
        }
    }
}