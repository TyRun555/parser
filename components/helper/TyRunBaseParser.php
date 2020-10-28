<?php

namespace app\components\helper;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use DateTime;
use DateTimeZone;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Набор базовых методов
 */
abstract class TyRunBaseParser
{
    /**
     * Базовый метод для разбора элемента, в основном запускается рекурсивно
     * @param Crawler $node текущий элемент
     * @param NewsPost $newPost объект новости
     * @param int $maxDepth максимальная глубина парсинга дочерних элементов
     * @param bool $stopParsing флаг о прекращении парсинга, передаваемый по ссылке, т.к. метод может быть вызван внутри замыкания
     * @return mixed
     */
    abstract protected static function parseNode(
        Crawler $node,
        NewsPost $newPost,
        int $maxDepth,
        bool &$stopParsing
    );

    /**
     * Парсер для тегов <a>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    protected static function parseLink(Crawler $node, NewsPost $newPost): void
    {
        $url = self::urlEncode($node->attr('href'));
        if (filter_var($url, FILTER_VALIDATE_URL)) {
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
     * Парсер для тегов <ul>, <ol> и т.п.
     * Разбирает списки в текст с переносом строки
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    protected static function parseUl(Crawler $node, NewsPost $newPost): void
    {
        $parsedUl = '';
        $node->filter('li')->each(function ($node) use (&$parsedUl) {
            $parsedUl .= static::UL_PREFIX . $node->text() . PHP_EOL;
        });
        if (!empty($parsedUl)) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    $parsedUl,
                    null,
                    null,
                    null,
                    null
                ));
        }
    }

    /**
     * Добавляет элемент "видео" в статью
     * @param string $videoId
     * @param NewsPost $newPost
     */
    protected static function addVideo(?string $videoId, NewsPost $newPost): void
    {
        if ($videoId) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_VIDEO,
                    null,
                    null,
                    null,
                    null,
                    $videoId
                ));
        }
    }

    /**
     * Парсер для тегов <img>
     * @param Crawler $node
     * @param NewsPost $newPost
     * @param string $lazySrcAttr название атрибута в котором лежит ссылка на изображение при lazyLoad
     */
    protected static function parseImage(Crawler $node, NewsPost $newPost, $lazySrcAttr = 'data-src'): void
    {
        $src = static::getProperImageSrc($node, $lazySrcAttr);
        if ($src && $src != $newPost->image) {
            if (empty($newPost->image)) {
                $newPost->image = $src;
            } else {
                $newPost->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_IMAGE,
                        null,
                        $src,
                        null,
                        null,
                        null
                    ));
            }
        }
    }

    /**
     * Возвращает id видео на youtube из url, если он есть
     * @param string $str
     * @return string|null
     */
    protected static function extractYouTubeId(string $str): ?string
    {
        /**
         * @see https://stackoverflow.com/questions/2936467/parse-youtube-video-id-using-preg-match
         */
        $pattern = '/(?:youtube(?:-nocookie)?\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';
        if (preg_match($pattern, $str, $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * Приводим дату к UTC +0
     * @param string $date
     * @param string $format
     * @return string
     */
    protected static function stringToDateTime(string $date, string $format = 'D, d M Y H:i:s O'): string
    {
        $dateTime = DateTime::createFromFormat($format, $date);
        if (is_a($dateTime, DateTime::class)) {
            $tz = new DateTimeZone('UTC');
            $dateTime->setTimezone($tz);
            return $dateTime->format('d-m-Y H:i:s');
        }
        return $date;
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
        $src = $node->attr('src') ?? $node->attr($lazySrcAttr);
        $src = self::absoluteUrl($src);
        return $src ? self::urlEncode($src) : false;
    }

    /**
     * Исправляет относительные ссылки на абсолютные для текущего сайта,
     * указанного в MAIN_PAGE_URI текущего класса
     * @param string $url
     * @return string|null
     */
    protected static function absoluteUrl(string $url): ?string
    {
        if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
            if (static::MAIN_PAGE_URI) {
                if (strpos($url, '/') == 0) {
                    return static::MAIN_PAGE_URI . $url;
                } else {
                    return static::MAIN_PAGE_URI . '/' . $url;
                }
            }
            return false;
        }
        return $url;
    }

    /**
     * Парсер для тегов <p> для сайтов у которых описание содержит часть текста статьи.
     * Дополнительно сверяем содержимое тегов с описанием новости (по предложениям), дубли не добавляем
     * @param Crawler $node текущий элемент для парсинга
     * @param NewsPost $newPost объект новости
     * @param array $descriptionSentences массив предложений описания новости (explode('. ', $description))
     */
    protected static function parseDescriptionIntersectParagraph(Crawler $node, NewsPost $newPost, array $descriptionSentences): void
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
            $text = trim(implode('. ', array_diff($nodeSentences, $intersect)));

            if (empty($text) || stristr($newPost->description, $text)) {
                return;
            }

            $type = NewsPostItem::TYPE_TEXT;
            if ($node->nodeName() == static::QUOTE_TAG) {
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

    /**
     * Кодирует кириллицу в ссылках, для прохождения валидации Url (FILTER_VALIDATE_URL)
     * @param string $url
     * @return string
     */
    protected static function urlEncode(string $url): string
    {
        return str_replace(['%3A', '%2F'], [':', '/'], rawurlencode($url));
    }

    /**
     * @param string $description
     * @param string $pattern
     * @return string
     *
     * Если В RSS битый дескрипшн:
     *  - например в конце идет коприайт, в виде ссылки на сайт:
     * [&#8230;] The post В Тверской области лишили прав водителя, ездившего " под кайфом" first appeared on TVTver.ru.
     * - Либо есть незаконченное предложение
     *
     * То обрезаем описание до последнего законченного предложения
     */
    protected static function prepareDescription(string $description, $pattern = ''): string
    {
        $description = Helper::prepareString($description);
        if ($pattern) {
            preg_match($pattern, $description, $matches);
        }
        return !empty($matches[1]) ? html_entity_decode($matches[1]) : html_entity_decode($description);
    }
}