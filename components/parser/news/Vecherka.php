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
 * Парсер новостей из RSS ленты https://vecherka.su
 *
 */
class Vecherka extends TyRunBaseParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const MAIN_PAGE_URI = 'https://vecherka.su';

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = 'article.inner';

    const QUOTE_TAG = 'blockquote';

    /**
     * CSS класс, где хранится контент новости
     */
    const CONTENT_CSS_SELECTOR = '.detail-text';

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
    const FEED_URL = 'https://vecherka.su/rss/';

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
    const MAX_NEWS_COUNT = 1;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $rss = $curl->get(self::FEED_URL);

        if (strpos($rss, '<?xml') !== 0) {
            $rss = '<?xml version="1.0" encoding="UTF-8"?>' . $rss;
        }
        $crawler = new Crawler($rss);
        $crawler->filter('rss channel item')->slice(6, self::MAX_NEWS_COUNT)->each(function (Crawler $node) use (&$curl, &$posts) {
            $newPost = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                $node->filter('description')->text(),
                self::stringToDateTime($node->filter('pubDate')->text()),
                $node->filter('link')->text(),
                $node->filter('enclosure')->count() ? $node->filter('enclosure')->attr('url') : null
            );

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

                $stopParsing = false;
                foreach ($articleContent->getNode(0)->childNodes as $node) {
                    if ($stopParsing) {
                        return;
                    }
                    self::parseRawNode($node, $newPost, self::MAX_PARSE_DEPTH, $stopParsing);
                }
            }

            $posts[] = $newPost;
        });

        return $posts;
    }

    protected static function parseRawNode($node, NewsPost $newPost, int $maxDepth, bool &$stopParsing): void
    {
        /**
         * Ограничение максимальной глубины парсинга
         * @see MAX_PARSE_DEPTH
         */
        if (!$maxDepth) {
            return;
        }
        $maxDepth--;

        if ($node->nodeName === 'p') {
            $text = trim($node->textContent);
            if ($text) {
                $newPost->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_TEXT,
                        $text,
                        null,
                        null,
                        null,
                        null
                    ));
            }
            if ($node->childNodes) {
                foreach ($node->childNodes as $childNode) {
                    if ($node->nodeName !== '#text') {
                        self::parseRawNode($childNode, $newPost, $maxDepth, $stopParsing);
                    }
                }
            }
        }else if (in_array($node->nodeName, ['b', 'h3'])) {
            $text = trim($node->textContent);
            if ($text) {
                $newPost->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_TEXT,
                        $text,
                        null,
                        null,
                        null,
                        null
                    ));
            }
        } else if ($node->nodeName === '#text' && $node->parentNode->nodeName !== 'p') {
            $text = trim($node->textContent);
            if ($text) {
                $newPost->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_TEXT,
                        $text,
                        null,
                        null,
                        null,
                        null
                    ));
            }
        } else if ($node->nodeName === 'img') {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_IMAGE,
                    null,
                    self::absoluteUrl($node->getAttribute('src')),
                    null,
                    null,
                    null
                ));
        } else if ($node->nodeName === 'blockquote') {
            $text = trim($node->textContent);
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_QUOTE,
                    $text,
                    null,
                    null,
                    null,
                    null
                ));
        } else if ($node->nodeName === 'a') {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_LINK,
                    null,
                    null,
                    self::absoluteUrl($node->getAttribute('href')),
                    null,
                    null
                ));
        }
    }

    protected static function parseNode(Crawler $node, NewsPost $newPost, int $maxDepth, bool &$stopParsing)
    {
        // not implemented
    }
}
