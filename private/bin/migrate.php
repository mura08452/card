<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/core.php';
require dirname(__DIR__).'/src/guest.php';
$c=require dirname(__DIR__).'/config.php';
migrateIdentity(database($c));
echo "Identity migration complete. Existing cards retained.\n";
