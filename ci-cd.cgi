#!/bin/bash

export RAW_JSON="$(cat)"

/usr/bin/php <<'EOF'
<?php

$_POST = getenv('RAW_JSON');
require "ci-cd.inc"

?>
EOF