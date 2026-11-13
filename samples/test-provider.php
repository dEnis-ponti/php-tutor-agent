<?php
require 'C:/dev/php-tutor-agent/vendor/autoload.php';

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

$client = (new GuzzleHttpClient())->withTimeout(600.0);
$provider = new Ollama(
    url: 'http://localhost:11434/api',
    model: 'php-tutor-qwen',
    httpClient: $client,
);

// сымитируем chat() из HandleChat, но с замером
$start = microtime(true);
try {
    $msg = $provider->chat(new Message(MessageRole::USER, 'Скажи одним словом: привет'));
    printf("OK за %.1fс: %s\n", microtime(true) - $start, substr($msg->getContent(), 0, 200));
} catch (\Throwable $e) {
    printf("FAIL за %.1fс: %s\n", microtime(true) - $start, $e->getMessage());
}
