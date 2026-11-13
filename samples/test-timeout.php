<?php
require 'C:/dev/php-tutor-agent/vendor/autoload.php';

use App\PhpTutorAgent;

$agent = new PhpTutorAgent();

// достаём провайдер через reflection (resolveProvider внутри Neuron)
$m = new ReflectionMethod($agent, 'provider');
$m->setAccessible(true);
$provider = $m->invoke($agent);

$rc = new ReflectionClass($provider);
$p = $rc->getProperty('httpClient');
$p->setAccessible(true);
$client = $p->getValue($provider);

$cc = new ReflectionClass($client);
$tp = $cc->getProperty('timeout');
$tp->setAccessible(true);
echo "Класс клиента: " . get_class($client) . "\n";
echo "timeout = " . var_export($tp->getValue($client), true) . "\n";
