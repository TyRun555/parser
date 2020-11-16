<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\TyRunBaseParser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Парсер новостей из RSS ленты big-rostov.ru
 *
 */
class BigRostov extends TyRunBaseParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const MAIN_PAGE_URI = 'https://big-rostov.ru';

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = '.br61-single-post-widget';

    /**
     * CSS  класс для параграфов - цитат
     */
    const QUOTE_TAG = 'em';

    /**
     * Классы эоементов, которые не нужно парсить, например блоки с рекламой и т.п.
     * в формате RegExp
     */
    const EXCLUDE_CSS_CLASSES_PATTERN = '/widget-area/';

    /**
     * Класс элемента после которого парсить страницу не имеет смысла (контент статьи закончился)
     */
    const CUT_CSS_CLASS = 'yarpp-related';


    /**
     * Ссылка на RSS фид (XML)
     */
    const FEED_URL = 'https://big-rostov.ru/feed/';

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
                $node->filter('description')->text(),
                self::stringToDateTime($node->filter('pubDate')->text()),
                $node->filter('link')->text(),
                null
            );

            /**
             * Предложения содержащиеся в описании (для последующей проверки при парсинга тела новости)
             */
            $descriptionSentences = explode('. ', html_entity_decode($newPost->description));
            if (count($descriptionSentences) > 2) {
                $descriptionSentences = array_slice($descriptionSentences, 0, 2);
                $newPost->description = implode('. ', $descriptionSentences);
            }

            /**
             * Получаем полный html новости
             */
            $newsContent = $curl->get($newPost->original);

            if (!empty($newsContent)) {
                $newsContent = (new Crawler($newsContent))->filter(self::BODY_CONTAINER_CSS_SELECTOR);

                /**
                 * Текст статьи, может содержать цитаты ( все полезное содержимое в тегах <p> )
                 * Не знаю нужно или нет, но сделал более универсально, с рекурсией
                 */
                $articleContent = $newsContent->filter('.entry-content')->children();
                $stopParsing = false;
                if ($articleContent->count()) {
                    $articleContent->each(function ($node) use ($newPost, &$stopParsing, $descriptionSentences) {
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
        if (self::CUT_CSS_CLASS &&
            (stristr($node->attr('class'), self::CUT_CSS_CLASS) ||
                $node->attr('id') == self::CUT_CSS_CLASS)
        ) {
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

        if ($node->text() == 'Поделиться:') {
            return;
        }

        switch ($node->nodeName()) {
            case 'div': //запускаем рекурсивно на дочерние ноды, если есть, если нет то там обычно ненужный шлак
                $nodes = $node->children();
                if ($nodes->count()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing, &$descriptionSentences) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing, $descriptionSentences);
                    });
                }
                break;
            case 'p':
            case 'span':
            case 'em':
                $linkImage = $node->filter('a > img');
                if ($linkImage->count()) {
                    $linkImage->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseImage($node, $newPost, 'data-lazy-src');
                    });
                }
                self::parseDescriptionIntersectParagraph($node, $newPost, $descriptionSentences);
                break;
            case 'h3':
            case 'h4':
            case 'h5':
                self::parseHeader($node, $newPost);
                break;
            case 'img':
                self::parseImage($node, $newPost, 'data-lazy-src');
                break;
            case 'video':
                $videoId = self::extractYouTubeId($node->filter('source')->first()->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'a':
                $linkImage = $node->filter('img');
                if ($linkImage->count() == 0) {
                    self::parseLink($node, $newPost);
                } else if ($linkImage->count()) {
                    self::parseImage($linkImage, $newPost, "data-lazy-src");
                } else {
                    if ($nodes = $node->children()) {
                        $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                            self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                        });
                    }
                }
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

    /**
     * Исправляет ссылку на изборажение:
     * - если атрибут src пустой, проверяем data-src (lazyload)
     * - если итоговый src не пустой и в классе указана константа MAIN_PAGE_UTI,
     * пробуем исправить ссылку, возможно ссылка относительная
     * @param Crawler $node элемент изображения
     * @param string $lazySrcAttr
     * @return string|null
     */
    protected static function getProperImageSrc(Crawler $node, string $lazySrcAttr): ?string
    {
        $src = !stristr($node->attr('src'), '1x1.trans.gif') ? $node->attr('src') : $node->attr($lazySrcAttr);
        $src = self::absoluteUrl($src);
        return $src ? self::urlEncode($src) : false;
    }

}