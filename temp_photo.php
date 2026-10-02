<?php
require 'vendor/autoload.php';
foreach (\App\Models\Location::whereNotNull('photo')->take(10) as $p) {
    echo $p->id.' -> '.$p->photo.PHP_EOL;
}