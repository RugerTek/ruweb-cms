<?php

use Illuminate\Http\Request;

$app = require __DIR__.'/api-key-only-bootstrap.php';
$app->handleRequest(Request::capture());
