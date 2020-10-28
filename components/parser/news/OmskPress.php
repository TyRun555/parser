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
 * Парсер новостей из RSS ленты omskpress.ru
 *
 */
class OmskPress extends TyRunBaseParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = '.article-post';

    /**
     * CSS  класс для параграфов - цитат
     */
    const QUOTE_TAG = '-';

    /**
     * Классы эоементов, которые не нужно парсить, например блоки с рекламой и т.п.
     * в формате RegExp
     */
    const EXCLUDE_CSS_CLASSES_PATTERN = '/date_social/';

    /**
     * Класс элемента после которого парсить страницу не имеет смысла (контент статьи закончился)
     */
    const CUT_CSS_CLASS = '';


    /**
     * Ссылка на RSS фид (XML)
     */
    const FEED_URL = 'https://omskpress.ru/feed/';

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
                self::prepareDescription($node->filter('description')->text(),
                    '/(.*)\.(.*)(\[&#8230;]|Сообщение)(.*)ОмскПресс\./'),
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
                /**
                 * Основное фото ( всегда одно в начале статьи)
                 */
                $mainImage = $newsContent->filter('.entry-image img');
                if ($mainImage->count()) {
                    if ($mainImage->attr('src')) {
                        $newPost->image = $mainImage->attr('src');
                    }
                }

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
        if (self::CUT_CSS_CLASS && stristr($node->attr('class'), self::CUT_CSS_CLASS)) {
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
            case 'div': //запускаем рекурсивно на дочерние ноды, если есть, если нет то там обычно ненужный шлак
            case 'span':
                $nodes = $node->children();
                if ($nodes->count()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'p':
                self::parseParagraph($node, $newPost, $descriptionSentences);
                if ($nodes = $node->children()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
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
                if ($nodes = $node->children()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
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
     * Парсер для тегов <a>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
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

    /**
     * Парсер для тегов <p>
     * Дополнительно сверяем содержимое тегов с описанием новости (по предложениям), дубли не добавляем
     * @param Crawler $node текущий элемент для парсинга
     * @param NewsPost $newPost объект новости
     * @param array $descriptionSentences массив предложений описания новости
     */
    private static function parseParagraph(Crawler $node, NewsPost $newPost, array $descriptionSentences): void
    {
        $nodeSentences = array_map(function ($item) {
            return !empty($item) ? trim($item, '  \t\n\r\0\x0B.') : false;
        }, explode('.', $node->text()));
        $intersect = array_intersect($nodeSentences, $descriptionSentences);

        /**
         * Если в тексте есть хоть одно уникальное предложение ( по сравнению с описанием новости )
         */
        if (!empty($node->text()) && count($intersect) < count($nodeSentences)) {
            /**
             * Дополнительно проверяем, что оставшийся текст не является подстрокой описания
             */
            $text = implode('. ', array_diff($nodeSentences, $intersect));
            if (empty($text) || stristr($newPost->description, $text)) {
                return;
            }

            $type = NewsPostItem::TYPE_TEXT;
            if ($node->nodeName() == self::QUOTE_TAG) {
                $type = NewsPostItem::TYPE_QUOTE;
            }

            $newPost->addItem(
                new NewsPostItem(
                    $type,
                    $text,
                    null,
                    null,
                    null,
                    null
                ));
        }
    }

}