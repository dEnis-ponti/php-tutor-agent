<?php
// Диагностика окружения php-tutor-agent. Запуск: php samples\diag.php
echo "PHP бинарь:      " . PHP_BINARY . "\n";
echo "PHP версия:      " . PHP_VERSION . "\n";
echo "php.ini:         " . (php_ini_loaded_file() ?: 'НЕ ЗАГРУЖЕН!') . "\n";
echo "curl:            " . (extension_loaded('curl') ? 'да' : 'НЕТ') . "\n";
echo "mbstring:        " . (extension_loaded('mbstring') ? 'да' : 'НЕТ') . "\n";
echo "openssl:         " . (extension_loaded('openssl') ? 'да' : 'НЕТ') . "\n";
echo "socket timeout:  " . ini_get('default_socket_timeout') . "\n";
echo "Ollama API:      ";
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
$tags = @file_get_contents('http://localhost:11434/api/tags', false, $ctx);
echo $tags !== false ? 'доступен' : 'НЕДОСТУПЕН (запусти Ollama)';
echo "\n";
