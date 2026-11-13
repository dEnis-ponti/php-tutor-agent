<?php
/**
 * PhpTutor Agent — точка входа.
 *
 *   php chat.php                     меню выбора урока (автодетект уроков в текущей папке)
 *   php chat.php 42                  сразу открыть урок 42
 *   php chat.php --lessons=<путь>    явная папка уроков
 *
 * Контракт формата курса и детали — в CONTEXT.md.
 */

require __DIR__ . '/vendor/autoload.php';

// qwen3:4b на CPU отвечает 1–3 минуты. php.ini в системе нет, а дефолтный
// сокетный таймаут PHP-обёртки (60 с) убивает запрос раньше ответа модели.
ini_set('default_socket_timeout', '600');

use App\CourseLoader;
use App\PhpTutorAgent;
use NeuronAI\Chat\Messages\UserMessage;

const PROGRESS_FILE = __DIR__ . '/progress.json';
const CONFIG_FILE = __DIR__ . '/config.json';

// ---------- аргументы и конфиг ----------

$options = getopt('', ['lessons::']);
$lessonArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (ctype_digit($arg)) {
        $lessonArg = (int) $arg;
    }
}

$config = is_file(CONFIG_FILE)
    ? (json_decode((string) file_get_contents(CONFIG_FILE), true) ?: [])
    : [];

try {
    $lessonsPath = CourseLoader::resolveLessonsPath($options['lessons'] ?? null, __DIR__);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "⚠️  {$e->getMessage()}\n");
    exit(1);
}

$course = new CourseLoader($lessonsPath);

$agent = new PhpTutorAgent(
    model: $config['model'] ?? 'php-tutor-qwen',
    ollamaUrl: $config['ollama_url'] ?? 'http://localhost:11434/api',
);

// ---------- прогресс ----------

$progress = is_file(PROGRESS_FILE)
    ? (json_decode((string) file_get_contents(PROGRESS_FILE), true) ?: [])
    : [];
$progress += ['level' => 0, 'weak_topics' => [], 'completed_lessons' => []];
$agent->setProgress($progress);

/**
 * Сливает [PROGRESS]...[/PROGRESS] из ответа модели в общий прогресс.
 */
function applyProgress(array &$progress, string $json): void
{
    $patch = json_decode($json, true);
    if (!is_array($patch)) {
        return;
    }
    if (isset($patch['level'])) {
        $progress['level'] = max((int) $progress['level'], (int) $patch['level']);
    }
    foreach (['weak_topics', 'completed_lessons'] as $key) {
        if (!empty($patch[$key]) && is_array($patch[$key])) {
            $progress[$key] = array_values(array_unique(array_merge($progress[$key], $patch[$key])));
        }
    }
    sort($progress['completed_lessons']);
    file_put_contents(PROGRESS_FILE, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ---------- меню ----------

/** Обрезка строки, UTF-8-безопасная даже без mbstring */
function strim(string $s, int $width): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $width, '…');
    }
    if (preg_match('/^.{0,' . $width . '}/us', $s, $m) && $m[0] !== $s) {
        return rtrim($m[0]) . '…';
    }
    return $s;
}


function showMenu(CourseLoader $course, array $progress): void
{
    $done = array_flip($progress['completed_lessons']);
    echo "\n📚 Курс: {$course->getLessonsPath()}\n";
    echo str_repeat('─', 60) . "\n";

    foreach ($course->listByTopics() as $topic => $lessons) {
        echo "\n  {$topic}\n";
        $line = [];
        foreach ($lessons as $meta) {
            $mark = isset($done[$meta['number']]) ? '✓' : ' ';
            $line[] = sprintf('[%d%s] %s', $meta['number'], $mark, strim($meta['title'], 40));
        }
        // компактно: по одному уроку в строке, но с отступом
        foreach ($line as $item) {
            echo "    {$item}\n";
        }
    }

    echo "\n" . str_repeat('─', 60) . "\n";
    echo "Введи номер урока (q — выход): ";
}

// ---------- основной цикл ----------

echo "PhpTutor готов. Уроки: {$lessonsPath}\n";

while (true) {
    if ($lessonArg === null) {
        showMenu($course, $progress);
        $input = trim((string) readline());
        if (in_array($input, ['q', 'quit', 'exit'], true)) {
            break;
        }
        if (!ctype_digit($input)) {
            echo "⚠️  Нужен номер урока.\n";
            continue;
        }
        $lessonArg = (int) $input;
    }

    try {
        $lesson = $course->loadLesson($lessonArg);
    } catch (\RuntimeException $e) {
        echo "⚠️  {$e->getMessage()}\n";
        $lessonArg = null;
        continue;
    }

    // новый агент на каждый урок — чистая история чата
    $agent = new PhpTutorAgent(
        model: $config['model'] ?? 'php-tutor-qwen',
        ollamaUrl: $config['ollama_url'] ?? 'http://localhost:11434/api',
    );
    $agent->setLesson($lesson);
    $agent->setProgress($progress);

    $doneMark = in_array($lesson['number'], $progress['completed_lessons'], true) ? ' (пройден ✓)' : '';
    echo "\n🎓 Урок №{$lesson['number']}: «{$lesson['title']}»{$doneMark}\n";
    echo "   'exit' — назад к списку уроков, 'quit' — выход.\n\n";

    while (true) {
        $input = trim((string) readline('Вы: '));
        if ($input === 'quit') {
            exit(0);
        }
        if ($input === 'exit' || $input === '') {
            break;
        }

        try {
            $response = $agent->chat(new UserMessage($input));
            $text = $response->getMessage()->getContent();

            if (preg_match_all('/\[PROGRESS\](.+?)\[\/PROGRESS\]/s', $text, $m)) {
                foreach ($m[1] as $json) {
                    applyProgress($progress, $json);
                }
                $text = str_replace($m[0], '', $text);
                echo "📈 Прогресс сохранён.\n";
            }
            echo "\nТьютор: " . trim($text) . "\n\n";
        } catch (\Throwable $e) {
            echo "\n⚠️  Ошибка: " . $e->getMessage() . "\n\n";
        }
    }

    $lessonArg = null; // вернулись в меню
}

echo "Пока! Прогресс — в progress.json.\n";
