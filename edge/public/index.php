<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Support\Config;
use Nico\Edge\Support\Log;

require __DIR__ . '/../autoload.php';
// routes.php declares functions rather than a class, so it is not autoloadable.
require __DIR__ . '/../src/Http/routes.php';

Config::boot(__DIR__ . '/../.env');
Log::init((string) Config::get('LOG_PATH', __DIR__ . '/../storage/logs/edge.log'));

$app = new App();
routes($app);
$app->run();
