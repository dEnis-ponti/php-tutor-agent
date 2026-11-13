# PhpTutor Agent

Локальный CLI-тьютор по PHP на базе [Neuron AI](https://github.com/neuron-core/neuron-ai) + [Ollama](https://ollama.com/).
Подключается к любому проекту с уроками и ведёт **отдельный чат по каждому уроку**:
объясняет тему, даёт практические задания, разбирает твои решения и ведёт общий прогресс.

Агент не привязан к конкретному курсу — он автоматически находит уроки в текущей папке
по контракту **course-format v1** (см. [CONTEXT.md](CONTEXT.md)).

```
$ php chat.php
📚 Курс: C:\projects\php-guru\src\lessons
────────────────────────────────────────────
  Основы
    [1 ] Введение в язык PHP для новичков
    [2✓] Начало работы с PHP
    ...
Введи номер урока (q — выход): 9
🎓 Урок №9: «Комментарии в PHP»
Вы: привет, расскажи про тему
Тьютор: ...
```

## Возможности

- Меню всех уроков курса, сгруппированных по темам, с отметками пройденных ✓
- В контекст тьютора попадают: полный текст урока (HTML очищается, код-блоки сохраняются),
  твои заметки из `notes.md`, общий прогресс
- Правила наставника: не даёт готовое решение до твоей попытки; разбор «что верно → ошибки → подсказка»
- Общий прогресс в `progress.json`: уровень, слабые темы, пройденные уроки
- Всё локально: никаких API-ключей и облаков

## Требования

| Что | Версия | Зачем |
|---|---|---|
| PHP (CLI) | 8.2+ | с расширениями **curl** и **mbstring** |
| Composer | 2.x | зависимости (`neuron-core/neuron-ai`) |
| Ollama | 0.40+ | локальная модель |
| RAM | 8+ ГБ | qwen3:4b (Q4_K_M, ~2.5 ГБ) |

> ⚡ **Производительность.** На CPU без GPU один ответ модели занимает **2–5 минут**
> (qwen3 «рассуждает» ~500–1000 токенов при ~10 ток/с). Для комфортной работы нужен GPU
> или более лёгкая модель — см. «Настройка модели» ниже.

## Установка

```bash
# 1. Клонировать и поставить зависимости
git clone https://github.com/dEnis-ponti/php-tutor-agent.git
cd php-tutor-agent
composer install

# 2. Скачать базовую модель и собрать из неё php-tutor-qwen
ollama pull qwen3:4b
ollama create php-tutor-qwen -f Modelfile

# 3. (опционально) конфиг по умолчанию
copy config.example.json config.json   # Windows
# cp config.example.json config.json   # Linux/macOS
```

Проверка установки:

```bash
php samples/diag.php
```

Должно показать: curl — да, mbstring — да, Ollama API — доступен.

### Windows: если PHP без php.ini (winget)

Winget-сборка PHP идёт **без php.ini** — расширения curl/mbstring не грузятся,
и запросы к модели обрываются на 60-й секунде. Лечение — создать `php.ini`
рядом с `php.exe`:

```ini
extension_dir = "ext"
extension = curl
extension = mbstring
extension = openssl
default_socket_timeout = 600
```

> Внимание: на одной машине может быть несколько PHP (например, в терминале
> VS Code `php` может резолвиться в npm-пакет `php-composer-cli` — у него свой
> php.ini без curl). Проверяй `php samples/diag.php` именно в том терминале,
> откуда запускаешь агента.

## Использование

```bash
# из папки проекта с уроками (уроки найдутся автоматически):
cd C:\projects\php-guru
php C:\dev\php-tutor-agent\chat.php          # меню уроков
php C:\dev\php-tutor-agent\chat.php 42       # сразу урок 42

# явный путь к урокам откуда угодно:
php C:\dev\php-tutor-agent\chat.php --lessons=D:\my-course\lessons
```

В чате: `exit` — назад к списку уроков, `quit` — выход.

Порядок поиска уроков: `--lessons=` → переменная окружения `PHP_TUTOR_LESSONS` →
автодетект в текущей папке (`src/lessons` → `lessons` → `dist/lessons`) → `lessons_path` из `config.json`.

### Формат курса (course-format v1)

Подойдёт любой проект, где уроки лежат так:

```
lessons/
  01/
    lesson.json    # {"number":1, "title":"...", "topic":"...", "contentHTML":"..."}
    notes.md       # необязательно: твои заметки, тьютор их видит
  02/
    lesson.json
```

Полный контракт с версионированием — в [CONTEXT.md](CONTEXT.md).

## Настройка модели

`config.json` (не коммитится, см. `config.example.json`):

```json
{
  "model": "php-tutor-qwen",
  "ollama_url": "http://localhost:11434/api",
  "lessons_path": "C:\\path\\to\\course\\src\\lessons"
}
```

- **Слабое железо / хочется быстрых ответов:** поставь instruct-модель без
  «рассуждений», например `ollama pull qwen2.5:3b-instruct`, и пропиши
  `"model": "qwen2.5:3b-instruct"` в `config.json`. Ответы — за секунды.
- **Своя система/промпт модели:** правится в `Modelfile`, затем
  `ollama create php-tutor-qwen -f Modelfile`.

## Troubleshooting

| Симптом | Причина и лечение |
|---|---|
| `Failed to open stream: HTTP request failed!` ровно через ~60 с | PHP без curl: запрос идёт через fopen-обёртку с таймаутом 60 с. Включи curl в php.ini (см. выше) |
| `Call to undefined function mb_strimwidth()` | Нет mbstring — включи в php.ini |
| `Typed property ...Workflow::$executor must not be accessed...` | Старая версия кода агента — обнови репозиторий |
| Модель «залипла», ответы висят | Разгрузи очередь: `ollama ps`, затем `ollama stop php-tutor-qwen` |
| Хочу проверить модель без агента | `curl http://localhost:11434/api/tags` — модель в списке? |
| Ответы очень медленные | Норма для CPU. GPU или instruct-модель (см. выше) |

## Структура

| Файл | Назначение |
|---|---|
| `chat.php` | Точка входа: меню уроков, цикл чата, запись прогресса |
| `src/PhpTutorAgent.php` | Агент Neuron AI: промпт с контекстом урока и прогрессом |
| `src/CourseLoader.php` | Автодетект и парсинг уроков, очистка HTML |
| `Modelfile` | Сборка модели `php-tutor-qwen` из qwen3:4b |
| `CONTEXT.md` | Контракт course-format v1 + заметки по окружению |
| `samples/` | Тестовый курс, диагностика (`diag.php`), примеры |
| `progress.json` | Прогресс ученика (создаётся при работе, не коммитится) |

## Лицензия

ISC
