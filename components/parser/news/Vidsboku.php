<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\TyRunBaseParser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей из RSS ленты http://vidsboku.com
 *
 */
class Vidsboku extends TyRunBaseParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const MAIN_PAGE_URI = 'http://vidsboku.com';

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = '.panel-col-first';

    const QUOTE_TAG = 'blockquote';

    /**
     * CSS класс, где хранится контент новости
     */
    const CONTENT_CSS_SELECTOR = '.pane-node-content .node-article .field-item';

    /**
     * Классы эоементов, которые не нужно парсить, например блоки с рекламой и т.п.
     * в формате RegExp
     */
    const EXCLUDE_CSS_CLASSES_PATTERN = '/element-invisible/';

    /**
     * Класс элемента после которого парсить страницу не имеет смысла (контент статьи закончился)
     */
    const CUT_CSS_REGEXP = '';

    /**
     * Ссылка на страницу всех новостей
     */
    const ALL_NEWS_URL = 'http://vidsboku.com/all/topics';

    /**
     *  Максимальная глубина для парсинга <div> тегов
     */
    const MAX_PARSE_DEPTH = 4;

    /**
     * Префикс для элементов списков (ul, ol и т.п.)
     * при преобразовании в текст
     * @see parseUl()
     */
    const UL_PREFIX = '-';

    /**
     * Кол-во новостей, которое необходимо парсить
     */
    const MAX_NEWS_COUNT = 10;

    private static int $addedNewCount = 0;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        if (self::MAX_NEWS_COUNT < 1) {
            return $posts;
        }
        $curl = Helper::getCurl();
        $html = $curl->get(self::ALL_NEWS_URL);
        $crawler = new Crawler($html);

        // Получаем количество страниц
        $lastPageHref = $crawler->filter('.pager .pager-last > a')->attr('href');
        preg_match('/\?page=([0-9]+)/', $lastPageHref, $matches);
        if(count($matches) !== 2) {
            return $posts;
        }
        $lastPageNumber = (int)$matches[1];
        for($page = 0; $page <= $lastPageNumber; $page++) {
            if ($page === 0) {
                $posts = array_merge($posts, self::parsePage($crawler, $curl));
            } else {
                $url = sprintf('%s?page=%s', self::ALL_NEWS_URL, $page);
                $html = $curl->get($url);
                $posts = array_merge($posts, self::parsePage(new Crawler($html), $curl));
            }
            if(self::$addedNewCount >= self::MAX_NEWS_COUNT) {
                break;
            }
        }

        return $posts;
    }

    protected static function parsePage(Crawler $crawler, Curl $curl): array
    {
        $posts = [];
        $crawler->filter('.panel-col-first .view-content .views-row')->each(function (Crawler $node) use (&$curl, &$posts) {
            if(self::$addedNewCount >= self::MAX_NEWS_COUNT) {
                return;
            }
            $title = $node->filter('.views-field-title')->text();
            $description = $node->filter('.views-field-body')->text();
            $link = $node->filter('.views-field-title a')->attr('href');

            $newPost = new NewsPost(
                self::class,
                $title,
                $description,
                '',
                self::absoluteUrl($link),
                null
            );

            $newsContent = $curl->get($newPost->original);
            if (!empty($newsContent)) {
                $newsContent = (new Crawler($newsContent))->filter(self::BODY_CONTAINER_CSS_SELECTOR);
                if (!$newsContent->count()) {
                    return;
                }

                $mainImage = $newsContent->filter('.pane-node-field-image img');
                if ($mainImage->count()) {
                    $newPost->image = $mainImage->attr('src');
                }

                $createdBlock = $newsContent->filter('.pane-node-created .pane-content');
                if ($createdBlock->count()) {
                    $created = trim($createdBlock->text());
                    $newPost->createDate = \DateTime::createFromFormat('l, j F, Y - H:i', self::ruStrDateToEn($created));
                }

                $articleContent = $newsContent->filter(self::CONTENT_CSS_SELECTOR);
                if (!$articleContent->count()) {
                    return;
                }

                $articleContent = $articleContent->children();
                $stopParsing = false;
                if ($articleContent->count()) {
                    $articleContent->each(function (Crawler $node) use ($newPost, &$stopParsing) {
                        if ($stopParsing) {
                            return;
                        }
                        self::parseNode($node, $newPost, self::MAX_PARSE_DEPTH, $stopParsing);
                    });
                }
            }

            self::$addedNewCount++;
            $posts[] = $newPost;
        });

        return $posts;
    }

    private static function ruStrDateToEn(string $ruDate): string
    {
        $replacements = [
            'понедельник' => 'monday',
            'вторник' => 'tuesday',
            'среда' => 'wednesday',
            'четверг' => 'thursday',
            'пятница' => 'friday',
            'суббота' => 'saturday',
            'воскресенье' => 'sunday',
            'января' => 'january',
            'февраля' => 'february',
            'марта' => 'march',
            'апреля' => 'april',
            'мая' => 'may',
            'июня' => 'june',
            'июля' => 'july',
            'августа' => 'august',
            'сентября' => 'september',
            'октября' => 'october',
            'ноября' => 'november',
            'декабря' => 'december',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $ruDate);
    }

    protected static function parseNode(Crawler $node, NewsPost $newPost, int $maxDepth, bool &$stopParsing): void
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
                if ($node->attr('class') === 'media media-element-container media-default') {
                    $image = $node->filter('.media-element-container img');
                    if ($image->count()) {
                        self::parseImage($image, $newPost);
                    }
                    break;
                }
                $nodes = $node->children();
                if ($nodes->count()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'p':
                if ($node->filter('.media-element-container')->count()) {
                    $image = $node->filter('.media-element-container img');
                    if ($image->count()) {
                        self::parseImage($image, $newPost);
                    }
                    break;
                }
                self::parseParagraph($node, $newPost);
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
                self::parseImage($node, $newPost);
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

    protected static function parseQuote(Crawler $node, NewsPost $newPost): void
    {
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

    protected static function parseLink(Crawler $node, NewsPost $newPost): void
    {
        $href = self::absoluteUrl($node->attr('href'));
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_LINK,
                    null,
                    null,
                    $href,
                    null,
                    null
                ));
        }
    }

    private static function parseParagraph(Crawler $node, NewsPost $newPost): void
    {
        if (!empty($node->text())) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    $node->text(),
                    null,
                    null,
                    null,
                    null
                ));
        }
    }
}
