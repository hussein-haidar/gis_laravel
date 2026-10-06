<?php
$radiusFile = 'resources/views/loc/radius.blade.php';
$distanceFile = 'resources/views/loc/distance.blade.php';

$radiusContent = file_get_contents('resources/views/loc/radius.blade.php');
$radiusContent = str_replace('route(\'admin.locations.radius.search\')', 'route(\'locations.radius.search\')', $radiusContent);
file_put_contents('resources/views/loc/radius.blade.php', $radiusContent);
echo "Updated radius.blade.php\n";

$distanceContent = file_get_contents('resources/views/loc/distance.blade.php');
$distanceContent = str_replace('route(\'admin.locations.distance\')', 'route(\'locations.distance\')', $distanceContent);
$distanceContent = str_replace('route(\'admin.locations.radius.search\')', 'route(\'locations.radius.search\')', $distanceContent);
file_put_contents($distanceFile, $distanceContent);
echo "Updated distance.blade.php\n";