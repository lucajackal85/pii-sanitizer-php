<?php

// End-to-end demo against a running engine:
//   PII_SOCKET_PATH=/tmp/sockets/pii_sanitizer.sock php examples/demo.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenPii\MonologSanitizer\Client\PiiSocketClient;
use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;

$socket = getenv('PII_SOCKET_PATH') ?: '/tmp/sockets/pii_sanitizer.sock';

$logger = new Logger('demo');
$handler = new StreamHandler('php://stdout');
$handler->pushProcessor(new PiiSanitizerProcessor(new PiiSocketClient($socket, 0.05, 2.0)));
$logger->pushHandler($handler);

$start = microtime(true);
$logger->error('Payment failed for John Doe', [
    'email' => 'john.doe@example.com',
    'phone' => '+44 7700 900123',
    'api_key' => 'sk_live_51HxQ2bLkdS8fPq9Zr7Tn3Vw',
    'amount' => 42.5,
]);
$logger->info('User login', ['user' => 'Maria Rossi', 'ip' => '10.0.0.7']);
printf("(2 records in %.1f ms)\n", (microtime(true) - $start) * 1000);
