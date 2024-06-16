#!/bin/bash

cd ~

apt update -y && apt install -y php

curl -o check.php https://raw.githubusercontent.com/FarshadGhanbari/checkserver/main/check.php

nohup php -S 0.0.0.0:20000 -t . > server.log 2>&1 &
