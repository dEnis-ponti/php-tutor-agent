<?php

namespace App;

/**
 * Загрузчик курса по контракту course-format v1 (см. CONTEXT.md).
 *
 * Папка уроков: подпапки с числовыми именами (NN/), в каждой lesson.json
 * (обязателен) и notes.md (опционален).
 */
class CourseLoader
{
    public const FORMAT_VERSION = 1;

    /**
     * Определяет папку уроков по приоритету:
     * явный путь → $PHP_TUTOR_LESSONS → автодетект в cwd → config.json
     *
     * @throws \RuntimeException если папку найти не удалось
     */
    public static function resolveLessonsPath(?string $explicit, string $agentDir, ?string $cwd = null): string
    {
        $cwd = $cwd ?? getcwd();

        $candidates = [];
        if ($explicit !== null && $explicit !== '') {
            $candidates[] = $explicit;
        }
        $env = getenv('PHP_TUTOR_LESSONS');
        if ($env !== false && $env !== '') {
            $candidates[] = $env;
        }
        foreach (['src/lessons', 'lessons', 'dist/lessons'] as $sub) {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . $sub;
        }

        $configFile = $agentDir . '/config.json';
        if (is_file($configFile)) {
            $config = json_decode((string) file_get_contents($configFile), true);
            if (!empty($config['lessons_path'])) {
                $candidates[] = $config['lessons_path'];
            }
        }

        foreach ($candidates as $path) {
            if (is_dir($path) && self::looksLikeLessonsDir($path)) {
                return $path;
            }
        }

        throw new \RuntimeException(
            "Папка уроков (course-format v" . self::FORMAT_VERSION . ") не найдена.\n" .
            "Запусти агент из папки проекта с уроками или передай --lessons=<путь>.\n" .
            "Проверено: " . implode(', ', $candidates)
        );
    }

    /**
     * Папка похожа на course-format v1, если в ней есть числовая подпапка с lesson.json.
     */
    public static function looksLikeLessonsDir(string $path): bool
    {
        foreach (scandir($path) ?: [] as $entry) {
            if (preg_match('/^\d+$/', $entry) && is_file($path . '/' . $entry . '/lesson.json')) {
                return true;
            }
        }
        return false;
    }

    public function __construct(private readonly string $lessonsPath)
    {
    }

    public function getLessonsPath(): string
    {
        return $this->lessonsPath;
    }

    /**
     * Список всех уроков, сгруппированный по темам.
     *
     * @return array<string, array<int, array{number:int, title:string, topic:string, hasNotes:bool}>>
     *         topic => [number => lesson-meta], темы и уроки отсортированы
     */
    public function listByTopics(): array
    {
        $topics = [];
        foreach ($this->lessonNumbers() as $number) {
            $meta = $this->loadMeta($number);
            if ($meta === null) {
                continue;
            }
            $topics[$meta['topic']][$number] = $meta;
        }

        foreach ($topics as &$lessons) {
            ksort($lessons);
        }
        unset($lessons);

        return $topics;
    }

    /**
     * Полные данные урока для контекста агента.
     *
     * @return array{number:int, title:string, topic:string, url:?string, text:string, notes:?string}
     * @throws \RuntimeException если урок не найден или битый
     */
    public function loadLesson(int $number): array
    {
        $file = $this->lessonsPath . '/' . $number . '/lesson.json';
        if (!is_file($file)) {
            // поддержка папок с ведущими нулями (01, 02…)
            $padded = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $file = $this->lessonsPath . '/' . $padded . '/lesson.json';
        }
        if (!is_file($file)) {
            throw new \RuntimeException("Урок №{$number} не найден в {$this->lessonsPath}");
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['title'], $data['contentHTML'])) {
            throw new \RuntimeException("Урок №{$number}: битый lesson.json (нет title/contentHTML)");
        }

        $notesFile = dirname($file) . '/notes.md';
        $notes = null;
        if (is_file($notesFile)) {
            $raw = trim((string) file_get_contents($notesFile));
            // пустой шаблон заметок не тащим в контекст
            if ($raw !== '' && !preg_match('/^#[^\n]*\s*(<!--.*?-->)?\s*$/s', $raw)) {
                $notes = $raw;
            }
        }

        return [
            'number' => (int) ($data['number'] ?? $number),
            'title'  => (string) $data['title'],
            'topic'  => (string) ($data['topic'] ?? 'Без темы'),
            'url'    => $data['url'] ?? null,
            'text'   => self::htmlToText((string) $data['contentHTML']),
            'notes'  => $notes,
        ];
    }

    /**
     * HTML урока → чистый текст. Блоки кода сохраняются.
     *
     * Порядок важен: код-блоки изымаются в плейсхолдеры ДО strip_tags
     * (иначе strip_tags вырежет <?php ... ?> как PHP-теги).
     */
    public static function htmlToText(string $html): string
    {
        // 1. <pre>/<code> → плейсхолдеры (содержимое декодируем сразу)
        $blocks = [];
        $html = preg_replace_callback(
            '#<(pre|code)[^>]*>(.*?)</\1>#si',
            function ($m) use (&$blocks) {
                $code = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $blocks[] = trim($code, "\n\r");
                return "\x01BLOCK" . (count($blocks) - 1) . "\x02";
            },
            $html
        );

        // 2. переносы строк в исходном HTML — просто пробелы внутри абзацев
        $html = preg_replace('/\s*\n\s*/', ' ', $html);

        // 3. блочные теги → переносы строк
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote)>#i', "\n", $html);
        $html = preg_replace('#<(br|hr)[^>]*>#i', "\n", $html);
        $html = preg_replace('#<li[^>]*>#i', "\n- ", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/ ?\n ?/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // 4. возвращаем код-блоки
        $text = preg_replace_callback(
            '/\x01BLOCK(\d+)\x02/',
            fn($m) => "\n```\n" . $blocks[(int) $m[1]] . "\n```\n",
            $text
        );

        return trim($text);
    }

    /**
     * @return int[] номера уроков по порядку
     */
    public function lessonNumbers(): array
    {
        $numbers = [];
        foreach (scandir($this->lessonsPath) ?: [] as $entry) {
            if (preg_match('/^\d+$/', $entry) && is_file($this->lessonsPath . '/' . $entry . '/lesson.json')) {
                $numbers[] = (int) $entry;
            }
        }
        sort($numbers);
        return $numbers;
    }

    /**
     * Лёгкие метаданные урока для меню (без contentHTML).
     */
    private function loadMeta(int $number): ?array
    {
        foreach ([$number, str_pad((string) $number, 2, '0', STR_PAD_LEFT)] as $dir) {
            $file = $this->lessonsPath . '/' . $dir . '/lesson.json';
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                return null;
            }
            return [
                'number'   => $number,
                'title'    => (string) ($data['title'] ?? "Урок {$number}"),
                'topic'    => (string) ($data['topic'] ?? 'Без темы'),
                'hasNotes' => is_file($this->lessonsPath . '/' . $dir . '/notes.md'),
            ];
        }
        return null;
    }
}
