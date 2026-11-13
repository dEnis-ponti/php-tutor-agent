<?php

namespace App;

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;

class PhpTutorAgent extends Agent
{
    /** Текущий урок (результат CourseLoader::loadLesson) */
    private ?array $lesson = null;

    /** Прогресс ученика (содержимое progress.json) */
    private array $progress = ['level' => 0, 'weak_topics' => [], 'completed_lessons' => []];

    public function __construct(
        private readonly string $model = 'php-tutor-qwen',
        private readonly string $ollamaUrl = 'http://localhost:11434/api',
    ) {
        parent::__construct(); // инициализация Workflow (executor и пр.)
    }

    public function setLesson(array $lesson): void
    {
        $this->lesson = $lesson;
    }

    public function setProgress(array $progress): void
    {
        $this->progress = $progress;
    }

    protected function provider(): AIProviderInterface
    {
        // qwen3:4b на CPU думает 1–3 минуты; рассуждения уходят в поле
        // thinking, а в content приходит готовый ответ — главное дождаться.
        // Дефолтный таймаут HTTP-клиента 60 с, поднимаем до 10 минут.
        $httpClient = (new GuzzleHttpClient())->withTimeout(600.0);

        return new Ollama(
            url: $this->ollamaUrl,
            model: $this->model,
            httpClient: $httpClient,
        );
    }

    public function instructions(): string
    {
        $progress = json_encode($this->progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $lessonBlock = 'Урок пока не выбран. Помоги ученику выбрать тему.';
        if ($this->lesson !== null) {
            $lesson = $this->lesson;
            $lessonBlock = <<<LESSON
            Текущий урок №{$lesson['number']}: «{$lesson['title']}» (тема: {$lesson['topic']})
            Источник: {$lesson['url']}

            === МАТЕРИАЛЫ УРОКА ===
            {$lesson['text']}
            === КОНЕЦ МАТЕРИАЛОВ ===
            LESSON;

            if (!empty($lesson['notes'])) {
                $lessonBlock .= "\n\n=== ЗАМЕТКИ УЧЕНИКА ПО УРОКУ ===\n{$lesson['notes']}\n=== КОНЕЦ ЗАМЕТОК ===";
            }
        }

        return <<<PROMPT
        Ты — PhpTutor, персональный наставник по PHP. Отвечай на русском, кратко и по делу.

        {$lessonBlock}

        Общий прогресс ученика (JSON): {$progress}

        Правила:
        - Занятие идёт строго по текущему уроку: объяснение до 10–15 строк + одно практическое задание.
        - Опирайся на материалы урока выше; не уходи в темы, которых в курсе ещё не было.
        - Никогда не давай готовое решение, пока ученик не пришлёт свою попытку.
        - Разбирая код: сначала что верно, потом ошибки, потом 1 подсказка.
        - Если у ученика есть заметки по уроку — учитывай их, отвечай на открытые вопросы из них.
        - Когда ученик уверенно справился с заданиями по уроку (без подсказок или с одной) —
          отметь это, выведя [PROGRESS]{"completed_lessons": [N]}[/PROGRESS], где N — номер урока.
        - Если видишь системную слабость (ошибки в теме из других уроков) — добавь её:
          [PROGRESS]{"weak_topics": ["тема"]}[/PROGRESS].
        PROMPT;
    }
}
