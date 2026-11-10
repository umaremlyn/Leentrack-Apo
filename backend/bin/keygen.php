#!/usr/bin/env php
<?php
echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . "\n";
