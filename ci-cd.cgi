#!/bin/bash

# Cabeçalhos HTTP necessários
echo ""

export RAW_JSON=$(cat)

# Corpo da página HTML
/usr/bin/php <<'EOF'
<?php

$_BASH = getenv('RAW_JSON');
require_once realpath('ci-cd.inc');

?>
EOF
